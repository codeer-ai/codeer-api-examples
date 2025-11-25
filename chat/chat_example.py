#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Codeer Chat API Example (Python)

This example demonstrates:
1. Creating a chat session with create_chat()
2. Sending messages with streaming responses via send_question()
3. Handling Server-Sent Events (SSE) for real-time streaming

Usage:
- Set CODEER_API_KEY, CODEER_API_ROOT, and CODEER_DEFAULT_AGENT
- Run: python chat_example.py
- Type messages and see streaming responses
- Commands: /new (new chat), /quit (exit)
"""

import sys
import requests
from typing import Optional, Callable
import io
import locale
import json

# Set locale to UTF-8
try:
    locale.setlocale(locale.LC_ALL, 'en_US.UTF-8')
except Exception:
    try:
        locale.setlocale(locale.LC_ALL, 'C.UTF-8')
    except Exception:
        pass

# Ensure UTF-8 encoding for stdout
if hasattr(sys.stdout, 'buffer'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace', line_buffering=True)
if hasattr(sys.stderr, 'buffer'):
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace', line_buffering=True)

# ============================================
# API Configuration
# ============================================
CODEER_API_KEY = "your_workspace_api_key"
CODEER_API_ROOT = "http://localhost:8000"
CODEER_DEFAULT_AGENT = None  # Optional: Set agent UUID or None for default agent

# ============================================
# API Functions
# ============================================

def create_chat(name: str = "Untitled", agent_id: Optional[str] = None) -> dict:
    """
    Create a new chat session
    Returns chat object with ID for subsequent messages
    """
    try:
        api_url = f"{CODEER_API_ROOT}/api/v1/chats"

        body = {
            "name": name,
        }

        effective_agent_id = agent_id or CODEER_DEFAULT_AGENT
        if effective_agent_id:
            body["agent_id"] = effective_agent_id

        response = requests.post(
            api_url,
            headers={
                "Content-Type": "application/json",
                "x-api-key": CODEER_API_KEY,
            },
            json=body,
        )

        try:
            resp = response.json()
        except Exception:
            resp = None

        if not response.ok or not resp or resp.get("error_code") != 0:
            message = None
            if isinstance(resp, dict):
                message = resp.get("message") or resp.get("error")
            if not message:
                message = f"Failed to create chat (HTTP {response.status_code})"
            raise Exception(f"API error: {message}")

        print(f"✅ New chat created: {resp}")
        return resp["data"]
    except Exception as err:
        print(f"❌ Error creating chat: {err}")
        raise


def list_published_agents():
    """
    List published agents for this workspace.
    Returns a list of agent dicts.
    """
    try:
        api_url = f"{CODEER_API_ROOT}/api/v1/chats/published-agents"

        response = requests.get(
            api_url,
            headers={
                "x-api-key": CODEER_API_KEY,
            },
        )

        try:
            resp = response.json()
        except Exception:
            resp = None

        if not response.ok or not resp or resp.get("error_code") != 0:
            message = None
            if isinstance(resp, dict):
                message = resp.get("message") or resp.get("error")
            if not message:
                message = f"Failed to list agents (HTTP {response.status_code})"
            raise Exception(f"API error: {message}")

        data = resp.get("data") or []
        return data
    except Exception as err:
        print(f"❌ Error listing agents: {err}")
        raise


def send_question(
    chat_id: int,
    payload: dict,
    on_message: Optional[Callable[[str], None]] = None,
    on_done: Optional[Callable[[], None]] = None,
    on_error: Optional[Callable[[Exception], None]] = None
):
    """
    Send a message and receive streaming response via Server-Sent Events (SSE)
    
    Args:
        chat_id: Chat session ID from create_chat()
        payload: { "message": str, "stream": bool, "agent_id"?: int }
        on_message: Called for each chunk of the response
        on_done: Called when streaming completes
        on_error: Called if an error occurs
    """
    try:
        api_url = f"{CODEER_API_ROOT}/api/v1/chats/{chat_id}/messages"
        
        response = requests.post(
            api_url,
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "x-api-key": CODEER_API_KEY,
            },
            json=payload,
        )
        response.encoding = "utf-8"

        error_data = None
        if not response.ok:
            try:
                error_data = response.json()
            except Exception:
                error_data = None
            message = None
            if isinstance(error_data, dict):
                message = error_data.get("message") or error_data.get("error")
            if not message:
                message = f"HTTP {response.status_code}"
            raise Exception(f"API error: {message}")
        
        # Parse SSE stream
        event_name = None
        data_lines = []
        done_called = False
        has_output_text = False

        def dispatch_event():
            nonlocal event_name, data_lines, done_called, has_output_text
            
            if not data_lines and not event_name:
                return False
            
            raw_payload = "\n".join(data_lines).strip()
            ev = (event_name or "").lower()

            if not raw_payload:
                event_name = None
                data_lines = []
                return False

            if raw_payload == "[DONE]":
                if on_done and not done_called:
                    on_done()
                    done_called = True
                return True

            parsed = None
            if raw_payload.startswith("{"):
                try:
                    parsed = json.loads(raw_payload)
                except Exception as e:
                    print(
                        f"Failed to parse SSE JSON: {e} | {raw_payload}",
                        file=sys.stderr,
                    )

            if ev == "error" or (isinstance(parsed, dict) and parsed.get("type") == "error"):
                message = None
                if isinstance(parsed, dict):
                    message = parsed.get("message") or parsed.get("error")
                if not message:
                    message = raw_payload or "Stream error"
                if on_error:
                    on_error(Exception(message))
                if on_done and not done_called:
                    on_done()
                    done_called = True
                return True

            if on_message:
                text_chunk: Optional[str] = None

                if (
                    isinstance(parsed, dict)
                    and parsed.get("type") == "response.output_text.delta"
                    and isinstance(parsed.get("delta"), str)
                ):
                    text_chunk = parsed["delta"]
                    has_output_text = True
                elif (
                    isinstance(parsed, dict)
                    and parsed.get("type") == "response.output_text.completed"
                    and isinstance(parsed.get("final_text"), str)
                    and not has_output_text
                ):
                    # Fallback if no deltas were streamed
                    text_chunk = parsed["final_text"]
                elif parsed is None:
                    # Legacy plain-text streaming fallback
                    text_chunk = raw_payload

                if text_chunk:
                    try:
                        on_message(text_chunk)
                    except Exception as e:
                        print(f"Error processing message: {e}", file=sys.stderr)
            
            event_name = None
            data_lines = []
            return False
        
        # Process streaming response line by line
        for line in response.iter_lines(decode_unicode=True):
            if line is None:
                continue
            
            # Ensure proper UTF-8 decoding
            if isinstance(line, bytes):
                line = line.decode('utf-8', errors='replace')
                
            # Empty line triggers event dispatch
            if line == "":
                if data_lines or event_name:
                    should_stop = dispatch_event()
                    if should_stop:
                        return
                continue
            
            # Skip comments
            if line.startswith(":"):
                continue
            
            # Parse event name
            if line.startswith("event:"):
                event_name = line[6:].strip()
                continue
            
            # Parse data
            if line.startswith("data:"):
                data_content = line[5:].lstrip()
                
                if data_content.strip() == "[DONE]":
                    if on_done and not done_called:
                        on_done()
                        done_called = True
                    return
                
                data_lines.append(data_content)
                continue
            
            # Additional data lines
            if data_lines:
                data_lines.append(line)
        
        # Final dispatch if there's remaining data
        if data_lines or event_name:
            dispatch_event()
        
        if on_done and not done_called:
            on_done()
            
    except Exception as err:
        print(f"SSE Error: {err}", file=sys.stderr)
        if on_error:
            on_error(err if isinstance(err, Exception) else Exception("Unknown error"))
        raise


# ============================================
# Interactive CLI
# ============================================

class ChatCLI:
    def __init__(self):
        self.chat_id = None
        self.agent_id = CODEER_DEFAULT_AGENT
        self.is_typing = False
        self.agents = []
    
    def print_welcome(self):
        """Print welcome message and instructions"""
        print("\n" + "=" * 60)
        print("💬 Codeer AI Chat - Python CLI")
        print("=" * 60)
        print("\nCommands:")
        print("  /new             - Start a new chat session")
        print("  /agents          - List published agents")
        print("  /agent <id|#>    - Change active agent (before /new)")
        print("  /quit            - Exit the application")
        current_agent = self.agent_id or "Workspace default"
        print(f"\nCurrent agent: {current_agent}")
        print("\nType your message and press Enter to chat.")
        print("=" * 60 + "\n")

    def list_agents(self):
        """Fetch and print available published agents"""
        try:
            agents = list_published_agents()
            self.agents = agents

            if not agents:
                print("\n📚 No published agents found.\n")
                return

            print("\n📚 Published agents:")
            for index, agent in enumerate(agents, start=1):
                agent_id_value = agent.get("id") or ""
                name = agent.get("name") or "Unnamed agent"
                description = agent.get("description") or ""
                is_current = agent_id_value == self.agent_id
                marker = " (current)" if is_current else ""
                print(f"  {index}. {name}{marker}")
                print(f"     ID: {agent_id_value}")
                if description:
                    print(f"     {description}")
            print("")
        except Exception as err:
            print(f"\n❌ Failed to list agents: {err}\n")

    def change_agent(self, agent_spec: str):
        """
        Change the active agent using an index from /agents
        or a full/partial agent ID. Only allowed when there
        is no active chat history.
        """
        if self.chat_id is not None:
            print(
                "\n⚠️  You already have an active chat."
                " Use /new to start a new chat before changing agent.\n"
            )
            return

        if not agent_spec:
            print("\nUsage: /agent <id|#>\n  - Use /agents to see available agents.\n")
            return

        if not self.agents:
            # Load agents if not already loaded
            self.list_agents()
            if not self.agents:
                return

        selected_agent = None

        # Try numeric index
        if agent_spec.isdigit():
            index = int(agent_spec) - 1
            if 0 <= index < len(self.agents):
                selected_agent = self.agents[index]
            else:
                print(f"\n❌ Invalid agent index: {agent_spec}\n")
                return
        else:
            # Match by full or prefix of ID
            for agent in self.agents:
                agent_id_value = str(agent.get("id") or "")
                if (
                    agent_id_value == agent_spec
                    or agent_id_value.startswith(agent_spec)
                ):
                    selected_agent = agent
                    break

            if selected_agent is None:
                print(
                    "\n❌ Agent not found. Use /agents to see available agents "
                    "and provide an index or full ID.\n"
                )
                return

        new_agent_id = selected_agent.get("id")
        if not new_agent_id:
            print("\n❌ Selected agent has no valid ID.\n")
            return

        if new_agent_id == self.agent_id:
            print("\nℹ️  Selected agent is already active.\n")
            return

        self.agent_id = new_agent_id
        name = selected_agent.get("name") or "Unnamed agent"
        print(f"\n✅ Active agent changed to: {name} ({self.agent_id})\n")
    
    def create_new_chat(self, name: str = "Untitled"):
        """Create a new chat session"""
        try:
            chat_data = create_chat(name[:256], self.agent_id)
            self.chat_id = chat_data["id"]
            print(f"🆕 Chat created with ID: {self.chat_id}\n")
        except Exception as e:
            print(f"❌ Failed to create chat: {e}\n")
            raise
    
    def send_message(self, message: str):
        """Send a message and handle streaming response"""
        if not self.chat_id:
            self.create_new_chat(message[:256])
        
        print("\n🤖 Assistant: ", end="", flush=True)
        
        self.is_typing = True
        first_chunk = True
        
        def on_message(data):
            nonlocal first_chunk
            if first_chunk:
                first_chunk = False
            print(data, end="", flush=True)
        
        def on_done():
            self.is_typing = False
            print("\n")
        
        def on_error(error):
            self.is_typing = False
            print(f"\n❌ Error: {error}")
            print("\nPlease check:")
            print("- API key is valid")
            print("- Backend server is running")
            print("- CORS is properly configured\n")
        
        try:
            send_question(
                self.chat_id,
                {
                    "message": message,
                    "stream": True,
                    "agent_id": self.agent_id,
                },
                on_message=on_message,
                on_done=on_done,
                on_error=on_error
            )
        except Exception as e:
            print(f"\n❌ Streaming error: {e}\n")
            self.is_typing = False
    
    def run(self):
        """Main interactive loop"""
        self.print_welcome()
        
        while True:
            try:
                user_input = input("💬 You: ").strip()
                
                if not user_input:
                    continue
                
                # Handle commands
                if user_input == "/quit":
                    print("\n👋 Goodbye!\n")
                    break
                
                if user_input == "/new":
                    self.chat_id = None
                    print("\n🔄 Starting new chat session...\n")
                    continue

                if user_input == "/agents":
                    self.list_agents()
                    continue

                if user_input.startswith("/agent"):
                    parts = user_input.split(maxsplit=1)
                    if len(parts) == 1:
                        self.change_agent("")
                    else:
                        self.change_agent(parts[1].strip())
                    continue
                
                # Send message
                self.send_message(user_input)
                
            except KeyboardInterrupt:
                print("\n\n👋 Goodbye!\n")
                break
            except EOFError:
                print("\n\n👋 Goodbye!\n")
                break
            except Exception as e:
                print(f"\n❌ Error: {e}\n")


# ============================================
# Main Entry Point
# ============================================

def main():
    """Main function to start the chat CLI"""
    try:
        cli = ChatCLI()
        cli.run()
    except Exception as e:
        print(f"Fatal error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
