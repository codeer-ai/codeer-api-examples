# Chat API Usage Examples

This directory contains comprehensive examples for the Codeer Chat API, demonstrating how to integrate conversational AI capabilities across different programming languages and frameworks.

## 📋 Table of Contents

- [Overview](#overview)
- [API Features](#api-features)
- [Example Descriptions](#example-descriptions)
- [Usage Guide](#usage-guide)
- [API Reference](#api-reference)
- [FAQ](#faq)

---

## Overview

The Codeer Chat API is a powerful conversational AI service that supports:

- ✅ **Real-time Streaming**: Instant responses via Server-Sent Events (SSE)
- ✅ **Session Management**: Create and manage multiple chat sessions
- ✅ **Multi-language Support**: Examples in Python, PHP, and JavaScript
- ✅ **Easy Integration**: Clean API design for seamless project integration

---

## API Features

### 1. Create Chat Session (`createChat`)

Creates a new conversation session and returns a session ID for subsequent messages.

**Endpoint:** `POST /api/v1/chats`

**Parameters:**
- `name` (string): Chat session name

**Returns:**
- `id` (number): Session ID
- Additional session information

### 2. Send Message (`sendQuestion`)

Sends a message to the specified session and receives streaming AI responses.

**Endpoint:** `POST /api/v1/chats/{history_id}/messages`

**Parameters:**
- `message` (string): User message
- `stream` (boolean): Enable streaming mode
- `agent_id` (number, optional): AI agent ID

**Response:** Server-Sent Events (SSE) stream

---

## Example Descriptions

### 🐍 Python CLI Example (`chat_example.py`)

**Features:**
- Interactive command-line chat interface
- UTF-8 encoding support
- Comprehensive error handling

**Usage:**
```bash
# Install dependencies
pip install -r ../requirements.txt

# Run the example
python chat_example.py
```

**Commands:**
- Type a message to start chatting
- `/new` - Start a new chat session
- `/quit` - Exit the program

**Key Functions:**
- `create_chat(name)` - Create a new session
- `send_question(history_id, payload, callbacks)` - Send messages and handle SSE streaming
- `ChatCLI` - Interactive command-line interface class

---

### 🐘 PHP CLI Example (`chat_example.php`)

**Features:**
- PHP command-line chat interface
- HTTP requests handled via cURL
- Readline support (when available)

**Usage:**
```bash
# Run the example
php chat_example.php
```

**Requirements:**
- PHP 7.4+
- cURL extension
- mbstring extension (for UTF-8 support)

**Key Functions:**
- `createChat($name)` - Create a new session
- `sendQuestion($historyId, $payload, $callbacks)` - Send messages and handle SSE
- `ChatCLI` - Interactive command-line interface class

---

### ⚛️ React Web Example (`react_chat.html`)

**Features:**
- Modern web chat interface
- Real-time message streaming
- Beautiful UI/UX design
- Quick-start example prompts

**Usage:**
```bash
# Option 1: Open directly in browser
open react_chat.html

# Option 2: Use a local server
python -m http.server 8080
# Then visit http://localhost:8080/react_chat.html
```

**Highlights:**
- Auto-scroll to latest messages
- Typing animation effects
- Streaming response cursor indicator
- Example prompt buttons

**Tech Stack:**
- React 18
- Fetch API (for SSE)
- Pure CSS styling

---

### 🟢 Vue Web Example (`vue_chat.html`)

**Features:**
- Vue.js-powered chat interface
- Responsive design
- Real-time message updates

**Usage:**
```bash
# Open in browser
open vue_chat.html
```

**Tech Stack:**
- Vue 3
- Composition API
- Fetch API

---

## Usage Guide

### Step 1: Configure API Credentials

Find and modify the following settings in the example files:

**Python:**
```python
CODEER_API_KEY = "your_api_key_here"
CODEER_API_ROOT = "http://localhost:8000"  # or your API server URL
```

**PHP:**
```php
define('CODEER_API_KEY', 'your_api_key_here');
define('CODEER_API_ROOT', 'http://localhost:8000');
```

**JavaScript (React/Vue):**
```javascript
const CODEER_API_KEY = "your_api_key_here";
const CODEER_API_ROOT = "http://localhost:8000";
```

### Step 2: Run the Example

Execute the appropriate example file for your chosen language (see example descriptions above).

### Step 3: Start Chatting

- Type your question or message
- AI will stream responses in real-time
- Use the `/new` command to start a new conversation (CLI versions)

---

## API Reference

### Request Headers

All API requests require the following headers:

```http
Content-Type: application/json
x-api-key: YOUR_API_KEY
```

### Server-Sent Events Format

API responses use SSE format:

```
event: message
data: Hello

event: message
data: World

data: [DONE]
```

**Event Types:**
- `message` - Message content
- `error` - Error message
- `[DONE]` - Stream end marker

### Error Handling

Example code includes comprehensive error handling:

```javascript
{
  on_error: (error) => {
    console.error("API Error:", error);
    // Handle error...
  }
}
```

**Common Errors:**
- **401**: Invalid API Key
- **404**: Session ID not found
- **500**: Server error

---

## FAQ

### Q: How do I get an API Key?

Contact your Codeer service provider to obtain your API key.

### Q: What languages does the AI support?

The API supports multilingual responses, including English, Chinese, and more.

### Q: What should I do if SSE streaming fails?

Check the following:
1. API Key is valid
2. Backend server is running
3. CORS is properly configured (for web versions)
4. Network connection is stable

### Q: Can I handle multiple chat sessions simultaneously?

Yes. Each session has a unique `history_id`, allowing you to create and manage multiple sessions.

### Q: How do I use this in production?

1. Set `CODEER_API_ROOT` to your production environment URL
2. Store API Key securely (use environment variables)
3. Implement proper error handling and retry mechanisms
4. Consider adding rate limiting and monitoring

### Q: Is non-streaming mode available?

The examples primarily demonstrate streaming mode (`stream: true`). You can set `stream` to `false` for complete responses, but note that the response format will differ.

---

## Advanced Usage

### Custom Agent ID

If your API supports multiple AI agents, you can specify an `agent_id`:

```javascript
{
  message: "Hello",
  stream: true,
  agent_id: 2  // Use a specific AI agent
}
```

### Session Management

In your application, it's recommended to:

1. **Store Session IDs**: Save `history_id` in your database or session storage
2. **Name Sessions**: Use meaningful names to identify different sessions
3. **Clean Up Old Sessions**: Periodically remove unused sessions

### Integration into Your Project

These example code samples are designed to be standalone and easy to understand. You can:

1. Copy the relevant language functions into your project
2. Adjust error handling and UI according to your needs
3. Add additional features like message history, user authentication, etc.

---

## Support

For questions or suggestions:

1. Review the detailed comments in the example code
2. Refer to the main [README](../README.md)
3. Submit an issue to the project repository

---

**[← Back to Main Documentation](../README.md)**

