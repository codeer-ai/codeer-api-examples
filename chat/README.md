# Chat API Examples

Short, practical examples for integrating the Codeer Chat API in Python, PHP, and the browser (React/Vue) with real‑time streaming via SSE.

---

## Quick Start

- Configure credentials at the top of the example you’re using.

Python (`chat/chat_example.py`):
```python
CODEER_API_KEY = "your_api_key_here"
CODEER_API_ROOT = "http://localhost:8000"
CODEER_DEFAULT_AGENT = None  # or "your_agent_uuid"
```

PHP (`chat/chat_example.php`):
```php
define('CODEER_API_KEY', 'your_api_key_here');
define('CODEER_API_ROOT', 'http://localhost:8000');
define('CODEER_DEFAULT_AGENT', null); // or 'your_agent_uuid'
```

JavaScript (React/Vue):
```javascript
const CODEER_API_KEY = "your_api_key_here";
const CODEER_API_ROOT = "http://localhost:8000";
const CODEER_DEFAULT_AGENT = undefined; // or "your_agent_uuid"
```

- Run one example:
  - Python CLI: `pip install -r ../requirements.txt && python chat_example.py`
  - PHP CLI: `php chat_example.php`
  - React: open `chat/react_chat.html` or serve with `python -m http.server 8080` and visit `http://localhost:8080/chat/react_chat.html`
  - Vue: open `chat/vue_chat.html` or serve with `python -m http.server 8080` and visit `http://localhost:8080/chat/vue_chat.html`

---

## API Overview

- Headers
```http
Content-Type: application/json
x-api-key: YOUR_API_KEY
```

- Endpoints
  - Create chat: `POST /api/v1/chats` (body: `{ "name": "My Chat" }`) → returns `id`
  - Send message: `POST /api/v1/chats/{history_id}/messages` (body: `{ message, stream: true, agent_id? }`) → SSE stream

- Streaming (SSE) shape
```
event: message
data: Hello

event: message
data: World

data: [DONE]
```

---

## API Reference

- Authentication
  - Send `x-api-key` in every request header.

- Create Chat
  - Method: `POST /api/v1/chats`
  - Body: `{ "name": string }` (optional, default "Untitled")
  - Success: `200` with JSON `{ data: { id: number, ... } }`
  - cURL:
    ```bash
    curl -X POST "$CODEER_API_ROOT/api/v1/chats" \
      -H 'Content-Type: application/json' \
      -H "x-api-key: $CODEER_API_KEY" \
      -d '{"name":"My Chat"}'
    ```

- Send Message (Streaming)
  - Method: `POST /api/v1/chats/{history_id}/messages`
  - Body: `{ "message": string, "stream": true, "agent_id"?: string|null }`
  - Success (streaming): `200` with `text/event-stream` where each `data:` line is a text chunk; terminates with `data: [DONE]`
  - Events: `event: message` (content), `event: error` (error description), `[DONE]` sentinel
  - cURL:
    ```bash
    curl -N -X POST "$CODEER_API_ROOT/api/v1/chats/$HISTORY_ID/messages" \
      -H 'Content-Type: application/json' \
      -H "x-api-key: $CODEER_API_KEY" \
      -d '{"message":"Hello","stream":true,"agent_id":null}'
    ```

- Send Message (Non‑streaming, optional)
  - Body: `{ "message": string, "stream": false, "agent_id"?: string|null }`
  - Success: `200` with full response in a single JSON payload (shape may vary by server version). Examples here focus on streaming.

- Error Responses
  - Status codes: `401` invalid API key, `404` unknown `history_id`, `500` server error
  - Body (typical): `{ "error": string }`

---

## Examples at a Glance

- Python CLI (`chat/chat_example.py`)
  - Interactive terminal chat; commands: `/new`, `/quit`
- PHP CLI (`chat/chat_example.php`)
  - Terminal chat using cURL; optional readline
- React Web (`chat/react_chat.html`)
  - Modern UI, streaming typing effect, example prompts
- Vue Web (`chat/vue_chat.html`)
  - Lightweight UI with real‑time streaming updates

---

## Troubleshooting

- Common HTTP errors: 401 (invalid API key), 404 (session not found), 500 (server error)
- Web/SSE issues: ensure the API is reachable, CORS is configured for your origin, and serve files via a local server when testing

---

## Agents (Optional)

- Use default agent: leave `CODEER_DEFAULT_AGENT` as `undefined`/`null`
- Use a specific agent: set `CODEER_DEFAULT_AGENT` to that agent’s UUID; it is sent as `agent_id` in the message payload

---

## Support

- See inline comments in the example files
- Refer to the main README: `../README.md`

**[← Back to Main Documentation](../README.md)**
