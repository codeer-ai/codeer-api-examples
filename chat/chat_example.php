#!/usr/bin/env php
<?php
/**
 * Codeer Chat API Example (PHP)
 *
 * This example demonstrates:
 * 1. Creating a chat session with createChat()
 * 2. Sending messages with streaming responses via sendQuestion()
 * 3. Handling Server-Sent Events (SSE) for real-time streaming
 *
 * Usage:
 * - Set CODEER_API_KEY, CODEER_API_ROOT, and CODEER_DEFAULT_AGENT
 * - Run: php chat_example.php
 * - Type messages and see streaming responses
 * - Commands: /new, /agents, /agent <id|#>, /quit
 */

// Set UTF-8 encoding
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// ============================================
// API Configuration
// ============================================
define('CODEER_API_KEY', 'your_workspace_api_key');
define('CODEER_API_ROOT', 'http://localhost:8000');
define('CODEER_DEFAULT_AGENT', null); // Optional: Set agent UUID or null for default agent

// ============================================
// API Functions
// ============================================

/**
 * Create a new chat session
 * Returns chat object with ID for subsequent messages
 *
 * @param string $name Chat name
 * @param string|null $agentId Agent ID override
 * @return array Chat data
 * @throws Exception
 */
function createChat($name = 'Untitled', $agentId = null) {
    try {
        $apiUrl = CODEER_API_ROOT . '/api/v1/chats';

        $body = [
            'name' => $name,
        ];
        $effectiveAgentId = $agentId ?: CODEER_DEFAULT_AGENT;
        if ($effectiveAgentId) {
            $body['agent_id'] = $effectiveAgentId;
        }

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . CODEER_API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = json_decode($response, true);
        if ($httpCode !== 200 || !is_array($resp) || ($resp['error_code'] ?? null) !== 0) {
            $message = $resp['message'] ?? $resp['error'] ?? sprintf('Failed to create chat (HTTP %d)', $httpCode);
            throw new Exception("API error: {$message}");
        }

        echo "✅ New chat created: " . json_encode($resp) . "\n";
        return $resp['data'];
    } catch (Exception $err) {
        echo "❌ Error creating chat: " . $err->getMessage() . "\n";
        throw $err;
    }
}

/**
 * List published agents for this workspace.
 *
 * @return array List of agents
 * @throws Exception
 */
function listPublishedAgents() {
    try {
        $apiUrl = CODEER_API_ROOT . '/api/v1/chats/published-agents';

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . CODEER_API_KEY,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = json_decode($response, true);
        if ($httpCode !== 200 || !is_array($resp) || ($resp['error_code'] ?? null) !== 0) {
            $message = $resp['message'] ?? $resp['error'] ?? sprintf('Failed to list agents (HTTP %d)', $httpCode);
            throw new Exception("API error: {$message}");
        }

        $data = $resp['data'] ?? [];
        return is_array($data) ? $data : [];
    } catch (Exception $err) {
        echo "❌ Error listing agents: " . $err->getMessage() . "\n";
        throw $err;
    }
}

/**
 * Send a message and receive streaming response via Server-Sent Events (SSE)
 *
 * @param int $chatId Chat session ID from createChat()
 * @param array $payload ['message' => string, 'stream' => bool, 'agent_id' => ?int]
 * @param callable|null $onMessage Called for each chunk of the response
 * @param callable|null $onDone Called when streaming completes
 * @param callable|null $onError Called if an error occurs
 * @throws Exception
 */
function sendQuestion($chatId, $payload, $onMessage = null, $onDone = null, $onError = null) {
    try {
        $apiUrl = CODEER_API_ROOT . "/api/v1/chats/{$chatId}/messages";
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'x-api-key: ' . CODEER_API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$onMessage, &$onDone, &$onError) {
                static $eventName = null;
                static $dataLines = [];
                static $doneCalled = false;
                static $buffer = '';
                static $hasOutputText = false;
                
                // Add new data to buffer
                $buffer .= $data;
                
                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    
                    // Remove carriage return if present
                    $line = rtrim($line, "\r");
                    
                    // Empty line triggers event dispatch
                    if ($line === '') {
                        if (!empty($dataLines) || $eventName !== null) {
                            $rawPayload = trim(implode("\n", $dataLines));
                            $ev = strtolower($eventName ?? '');

                            if ($rawPayload === '') {
                                $eventName = null;
                                $dataLines = [];
                                continue;
                            }

                            if ($rawPayload === '[DONE]') {
                                if ($onDone && !$doneCalled) {
                                    $onDone();
                                    $doneCalled = true;
                                }
                                return strlen($data);
                            }

                            $parsed = null;
                            if (strpos($rawPayload, '{') === 0) {
                                $parsed = json_decode($rawPayload, true);
                            }

                            if ($ev === 'error' || (is_array($parsed) && ($parsed['type'] ?? null) === 'error')) {
                                $message = null;
                                if (is_array($parsed)) {
                                    $message = $parsed['message'] ?? $parsed['error'] ?? null;
                                }
                                if (!$message) {
                                    $message = $rawPayload ?: 'Stream error';
                                }
                                if ($onError) {
                                    $onError(new Exception($message));
                                }
                                if ($onDone && !$doneCalled) {
                                    $onDone();
                                    $doneCalled = true;
                                }
                                return strlen($data);
                            }

                            if ($onMessage) {
                                $textChunk = null;

                                if (
                                    is_array($parsed) &&
                                    ($parsed['type'] ?? null) === 'response.output_text.delta' &&
                                    isset($parsed['delta']) &&
                                    is_string($parsed['delta'])
                                ) {
                                    $textChunk = $parsed['delta'];
                                    $hasOutputText = true;
                                } elseif (
                                    is_array($parsed) &&
                                    ($parsed['type'] ?? null) === 'response.output_text.completed' &&
                                    isset($parsed['final_text']) &&
                                    is_string($parsed['final_text']) &&
                                    !$hasOutputText
                                ) {
                                    // Fallback if no deltas were streamed
                                    $textChunk = $parsed['final_text'];
                                } elseif ($parsed === null) {
                                    // Legacy plain-text streaming fallback
                                    $textChunk = $rawPayload;
                                }

                                if ($textChunk !== null && $textChunk !== '') {
                                    try {
                                        $onMessage($textChunk);
                                    } catch (Exception $e) {
                                        fwrite(STDERR, "Error processing message: " . $e->getMessage() . "\n");
                                    }
                                }
                            }
                            
                            $eventName = null;
                            $dataLines = [];
                        }
                        continue;
                    }
                    
                    // Skip comments
                    if (strpos($line, ':') === 0) {
                        continue;
                    }
                    
                    // Parse event name
                    if (strpos($line, 'event:') === 0) {
                        $eventName = trim(substr($line, 6));
                        continue;
                    }
                    
                    // Parse data
                    if (strpos($line, 'data:') === 0) {
                        $dataContent = ltrim(substr($line, 5));
                        
                        if (trim($dataContent) === '[DONE]') {
                            if ($onDone && !$doneCalled) {
                                $onDone();
                                $doneCalled = true;
                            }
                            return strlen($data);
                        }
                        
                        $dataLines[] = $dataContent;
                        continue;
                    }
                    
                    // Additional data lines
                    if (!empty($dataLines)) {
                        $dataLines[] = $line;
                    }
                }
                
                return strlen($data);
            },
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("API error: {$error} ({$httpCode})");
        }
        
        curl_close($ch);
        
    } catch (Exception $err) {
        fwrite(STDERR, "SSE Error: " . $err->getMessage() . "\n");
        if ($onError) {
            $onError($err);
        }
        throw $err;
    }
}

// ============================================
// Interactive CLI
// ============================================

class ChatCLI {
    private $chatId = null;
    private $agentId = CODEER_DEFAULT_AGENT;
    private $isTyping = false;
    private $agents = [];
    
    /**
     * Print welcome message and instructions
     */
    public function printWelcome() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "💬 Codeer AI Chat - PHP CLI\n";
        echo str_repeat("=", 60) . "\n";
        echo "\nCommands:\n";
        echo "  /new             - Start a new chat session\n";
        echo "  /agents          - List published agents\n";
        echo "  /agent <id|#>    - Change active agent (before /new)\n";
        echo "  /quit            - Exit the application\n";
        $currentAgent = $this->agentId ?: 'Workspace default';
        echo "\nCurrent agent: {$currentAgent}\n";
        echo "\nType your message and press Enter to chat.\n";
        echo str_repeat("=", 60) . "\n\n";
    }

    /**
     * Fetch and print available published agents
     */
    public function listAgents() {
        try {
            $agents = listPublishedAgents();
            $this->agents = $agents;

            if (empty($agents)) {
                echo "\n📚 No published agents found.\n\n";
                return;
            }

            echo "\n📚 Published agents:\n";
            $index = 1;
            foreach ($agents as $agent) {
                $agentIdValue = isset($agent['id']) ? (string)$agent['id'] : '';
                $name = isset($agent['name']) && $agent['name'] !== '' ? $agent['name'] : 'Unnamed agent';
                $description = isset($agent['description']) ? (string)$agent['description'] : '';
                $isCurrent = $agentIdValue !== '' && $agentIdValue === $this->agentId;
                $marker = $isCurrent ? ' (current)' : '';
                echo sprintf("  %d. %s%s\n", $index, $name, $marker);
                echo "     ID: {$agentIdValue}\n";
                if ($description !== '') {
                    echo "     {$description}\n";
                }
                $index++;
            }
            echo "\n";
        } catch (Exception $err) {
            echo "\n❌ Failed to list agents: " . $err->getMessage() . "\n\n";
        }
    }

    /**
     * Change the active agent using an index from /agents
     * or a full/partial agent ID. Only allowed when there
     * is no active chat history.
     *
     * @param string $agentSpec
     */
    public function changeAgent($agentSpec) {
        if ($this->chatId !== null) {
            echo "\n⚠️  You already have an active chat. Use /new to start a new chat before changing agent.\n\n";
            return;
        }

        $agentSpec = trim($agentSpec);
        if ($agentSpec === '') {
            echo "\nUsage: /agent <id|#>\n  - Use /agents to see available agents.\n\n";
            return;
        }

        if (empty($this->agents)) {
            // Load agents if not already loaded
            $this->listAgents();
            if (empty($this->agents)) {
                return;
            }
        }

        $selectedAgent = null;

        // Try numeric index
        if (ctype_digit($agentSpec)) {
            $index = (int)$agentSpec - 1;
            if ($index >= 0 && $index < count($this->agents)) {
                $selectedAgent = $this->agents[$index];
            } else {
                echo "\n❌ Invalid agent index: {$agentSpec}\n\n";
                return;
            }
        } else {
            // Match by full or prefix of ID
            foreach ($this->agents as $agent) {
                $agentIdValue = isset($agent['id']) ? (string)$agent['id'] : '';
                if ($agentIdValue === $agentSpec || strpos($agentIdValue, $agentSpec) === 0) {
                    $selectedAgent = $agent;
                    break;
                }
            }

            if ($selectedAgent === null) {
                echo "\n❌ Agent not found. Use /agents to see available agents and provide an index or full ID.\n\n";
                return;
            }
        }

        $newAgentId = isset($selectedAgent['id']) ? (string)$selectedAgent['id'] : '';
        if ($newAgentId === '') {
            echo "\n❌ Selected agent has no valid ID.\n\n";
            return;
        }

        if ($newAgentId === $this->agentId) {
            echo "\nℹ️  Selected agent is already active.\n\n";
            return;
        }

        $this->agentId = $newAgentId;
        $name = isset($selectedAgent['name']) && $selectedAgent['name'] !== '' ? $selectedAgent['name'] : 'Unnamed agent';
        echo "\n✅ Active agent changed to: {$name} ({$this->agentId})\n\n";
    }
    
    /**
     * Create a new chat session
     *
     * @param string $name Chat name
     * @throws Exception
     */
    public function createNewChat($name = 'Untitled') {
        try {
            $chatData = createChat(substr($name, 0, 256), $this->agentId);
            $this->chatId = $chatData['id'];
            echo "🆕 Chat created with ID: {$this->chatId}\n\n";
        } catch (Exception $e) {
            echo "❌ Failed to create chat: " . $e->getMessage() . "\n\n";
            throw $e;
        }
    }
    
    /**
     * Send a message and handle streaming response
     *
     * @param string $message User message
     */
    public function sendMessage($message) {
        if (!$this->chatId) {
            $this->createNewChat(substr($message, 0, 256));
        }
        
        echo "\n🤖 Assistant: ";
        flush();
        
        $this->isTyping = true;
        $firstChunk = true;
        
        $onMessage = function($data) use (&$firstChunk) {
            if ($firstChunk) {
                $firstChunk = false;
            }
            echo $data;
            flush();
        };
        
        $onDone = function() {
            $this->isTyping = false;
            echo "\n\n";
        };
        
        $onError = function($error) {
            $this->isTyping = false;
            echo "\n❌ Error: " . $error->getMessage() . "\n";
            echo "\nPlease check:\n";
            echo "- API key is valid\n";
            echo "- Backend server is running\n";
            echo "- CORS is properly configured\n\n";
        };
        
        try {
            sendQuestion(
                $this->chatId,
                [
                    'message' => $message,
                    'stream' => true,
                    'agent_id' => $this->agentId,
                ],
                $onMessage,
                $onDone,
                $onError
            );
        } catch (Exception $e) {
            echo "\n❌ Streaming error: " . $e->getMessage() . "\n\n";
            $this->isTyping = false;
        }
    }
    
    /**
     * Main interactive loop
     */
    public function run() {
        $this->printWelcome();
        
        // Enable readline if available
        $hasReadline = function_exists('readline');
        
        while (true) {
            try {
                if ($hasReadline) {
                    $userInput = readline("💬 You: ");
                } else {
                    echo "💬 You: ";
                    $userInput = fgets(STDIN);
                }
                
                if ($userInput === false) {
                    echo "\n\n👋 Goodbye!\n\n";
                    break;
                }
                
                $userInput = trim($userInput);
                
                if (empty($userInput)) {
                    continue;
                }
                
                // Handle commands
                if ($userInput === '/quit') {
                    echo "\n👋 Goodbye!\n\n";
                    break;
                }
                
                if ($userInput === '/new') {
                    $this->chatId = null;
                    echo "\n🔄 Starting new chat session...\n\n";
                    continue;
                }

                if ($userInput === '/agents') {
                    $this->listAgents();
                    continue;
                }

                if (strpos($userInput, '/agent') === 0) {
                    $parts = preg_split('/\s+/', $userInput, 2);
                    $arg = isset($parts[1]) ? trim($parts[1]) : '';
                    $this->changeAgent($arg);
                    continue;
                }
                
                // Send message
                $this->sendMessage($userInput);
                
            } catch (Exception $e) {
                echo "\n❌ Error: " . $e->getMessage() . "\n\n";
            }
        }
    }
}

// ============================================
// Main Entry Point
// ============================================

/**
 * Main function to start the chat CLI
 */
function main() {
    try {
        $cli = new ChatCLI();
        $cli->run();
    } catch (Exception $e) {
        fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli') {
    main();
}
?>
