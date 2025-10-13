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
 * - Set CODEER_API_KEY and CODEER_API_ROOT
 * - Run: php chat_example.php
 * - Type messages and see streaming responses
 * - Commands: /new (new chat), /quit (exit)
 */

// Set UTF-8 encoding
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// ============================================
// API Configuration
// ============================================
define('CODEER_API_KEY', 'your_workspace_api_key');
define('CODEER_API_ROOT', 'http://localhost:8000');

// ============================================
// API Functions
// ============================================

/**
 * Create a new chat session
 * Returns chat object with ID for subsequent messages
 *
 * @param string $name Chat name
 * @return array Chat data
 * @throws Exception
 */
function createChat($name = 'Untitled') {
    try {
        $apiUrl = CODEER_API_ROOT . '/api/v1/chats';
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . CODEER_API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode(['name' => $name]),
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            throw new Exception(
                sprintf("API error: %s (%d)", 
                    $errorData['error'] ?? 'Failed to create chat', 
                    $httpCode
                )
            );
        }
        
        $resp = json_decode($response, true);
        echo "✅ New chat created: " . json_encode($resp) . "\n";
        return $resp['data'];
    } catch (Exception $err) {
        echo "❌ Error creating chat: " . $err->getMessage() . "\n";
        throw $err;
    }
}

/**
 * Send a message and receive streaming response via Server-Sent Events (SSE)
 *
 * @param int $historyId Chat session ID from createChat()
 * @param array $payload ['message' => string, 'stream' => bool, 'agent_id' => ?int]
 * @param callable|null $onMessage Called for each chunk of the response
 * @param callable|null $onDone Called when streaming completes
 * @param callable|null $onError Called if an error occurs
 * @throws Exception
 */
function sendQuestion($historyId, $payload, $onMessage = null, $onDone = null, $onError = null) {
    try {
        $apiUrl = CODEER_API_ROOT . "/api/v1/chats/{$historyId}/messages";
        
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
                            $payloadData = implode("\n", $dataLines);
                            $ev = strtolower($eventName ?? '');
                            
                            if (trim($payloadData) === '[DONE]') {
                                if ($onDone && !$doneCalled) {
                                    $onDone();
                                    $doneCalled = true;
                                }
                                return strlen($data);
                            }
                            
                            if ($ev === 'error') {
                                if ($onError) {
                                    $onError(new Exception($payloadData ?: 'Stream error'));
                                }
                                if ($onDone && !$doneCalled) {
                                    $onDone();
                                    $doneCalled = true;
                                }
                                return strlen($data);
                            }
                            
                            if ($onMessage && $payloadData) {
                                try {
                                    $onMessage($payloadData);
                                } catch (Exception $e) {
                                    fwrite(STDERR, "Error processing message: " . $e->getMessage() . "\n");
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
    private $historyId = null;
    private $agentId = null;
    private $isTyping = false;
    
    /**
     * Print welcome message and instructions
     */
    public function printWelcome() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "💬 Codeer AI Chat - PHP CLI\n";
        echo str_repeat("=", 60) . "\n";
        echo "\nCommands:\n";
        echo "  /new  - Start a new chat session\n";
        echo "  /quit - Exit the application\n";
        echo "\nType your message and press Enter to chat.\n";
        echo str_repeat("=", 60) . "\n\n";
    }
    
    /**
     * Create a new chat session
     *
     * @param string $name Chat name
     * @throws Exception
     */
    public function createNewChat($name = 'Untitled') {
        try {
            $chatData = createChat(substr($name, 0, 256));
            $this->historyId = $chatData['id'];
            echo "🆕 Chat created with ID: {$this->historyId}\n\n";
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
        if (!$this->historyId) {
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
                $this->historyId,
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
                    $this->historyId = null;
                    echo "\n🔄 Starting new chat session...\n\n";
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

