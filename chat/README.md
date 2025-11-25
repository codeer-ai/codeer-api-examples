# Chat API Examples

Short, practical examples for integrating the Codeer Chat API in Python, PHP, and the browser (React/Vue) with real‑time, structured streaming via SSE.

---

## Quick Start

- Configure credentials at the top of the example you’re using.

Python (`chat/chat_example.py`):
```python
CODEER_API_KEY = "your_api_key_here"
CODEER_API_ROOT = "http://localhost:8000"
CODEER_DEFAULT_AGENT = "your_agent_uuid_here"  # id from /api/v1/chats/published-agents
```

PHP (`chat/chat_example.php`):
```php
define('CODEER_API_KEY', 'your_api_key_here');
define('CODEER_API_ROOT', 'http://localhost:8000');
define('CODEER_DEFAULT_AGENT', 'your_agent_uuid_here'); // id from /api/v1/chats/published-agents
```

JavaScript (React/Vue):
```javascript
const CODEER_API_KEY = "your_api_key_here";
const CODEER_API_ROOT = "http://localhost:8000";
const CODEER_DEFAULT_AGENT = "your_agent_uuid_here"; // id from /api/v1/chats/published-agents
```

- Run one example:
  - Python CLI: `pip install -r ../requirements.txt && python chat_example.py`
  - PHP CLI: `php chat_example.php`
  - React: open `chat/react_chat.html` or serve with `python -m http.server 8080` and visit `http://localhost:8080/chat/react_chat.html`
  - Vue: open `chat/vue_chat.html` or serve with `python -m http.server 8080` and visit `http://localhost:8080/chat/vue_chat.html`

All examples call the HTTP API described below.

---

## API Overview

- Base URL
  - `CODEER_API_ROOT` (e.g. `http://localhost:8000`)

- Headers
  ```http
  Content-Type: application/json
  x-api-key: YOUR_API_KEY
  ```

- Endpoints (summary)
  - List published agents: `GET /api/v1/chats/published-agents`
  - Create chat: `POST /api/v1/chats`
  - List chats: `GET /api/v1/chats`
  - List chat messages: `GET /api/v1/chats/{chat_id}/messages`
  - Upload file: `POST /api/v1/chats/upload-file`
  - Send message: `POST /api/v1/chats/{chat_id}/messages` (body: `{ message, stream, agent_id, attached_file_uuids? }`)

- Streaming (SSE) shape (Responses-style)
  ```text
  event: response.created
  data: {"type":"response.created","response_id":"...","chat_id":123,...}

  event: response.output_text.delta
  data: {"type":"response.output_text.delta","response_id":"...","chat_id":123,"delta":"Hello"}

  event: response.output_text.completed
  data: {"type":"response.output_text.completed","response_id":"...","chat_id":123,"final_text":"Hello world",...}

  data: [DONE]
  ```

---

## API Reference

### Authentication

- Send `x-api-key` in every request header.
- Keys are workspace-scoped; you can create them in the Django admin.

---

### List Published Agents

- Method: `GET /api/v1/chats/published-agents`
- Headers:
  - `x-api-key: YOUR_API_KEY`
- Query parameters: none
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "message": null,
    "data": [
      {
        "id": "b6a3d9c3-8b1e-4de3-9a3c-2f1b5f7b8a01",
        "name": "Support Copilot",
        "description": "Answer support questions using your knowledge base",
        "agent_type": "assistant",
        "llm_model": "gpt-4o",
        "use_search": true,
        "version": 3
      }
    ]
  }
  ```

Example:

```bash
curl "$CODEER_API_ROOT/api/v1/chats/published-agents" \
  -H "x-api-key: $CODEER_API_KEY"
```

You will use the `id` property as `agent_id` in later calls.

---

### Create Chat

- Method: `POST /api/v1/chats`
- Headers:
  - `Content-Type: application/json`
  - `x-api-key: YOUR_API_KEY`
- Query parameters (optional):
  - `external_user_id`: your own identifier for the end user (e.g. `"user-123"`).
- Body:
  ```json
  {
    "name": "My Chat Title",
    "agent_id": "AGENT_UUID"
  }
  ```
  - `name` (string, max 256, required) – chat title. If empty, the backend will fall back to a generic name like `"Untitled"` when reading.
  - `agent_id` (UUID, required) – from `/api/v1/chats/published-agents`.
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "data": {
      "id": 123,
      "name": "My Chat Title",
      "created_at": "2024-01-01T00:00:00Z",
      "updated_at": "2024-01-01T00:00:00Z",
      "meta": {
        "conversation_agent_id": "AGENT_UUID",
        "external_user_id": "user-123"
      },
      "external_user_id": "user-123"
    }
  }
  ```

Example:

```bash
curl -X POST "$CODEER_API_ROOT/api/v1/chats?external_user_id=user-123" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $CODEER_API_KEY" \
  -d '{
    "name": "Support chat for user-123",
    "agent_id": "b6a3d9c3-8b1e-4de3-9a3c-2f1b5f7b8a01"
  }'
```

The returned `data.id` is the `chat_id` you will use when sending messages (this is also the `history_id` / `chat_id` field in SSE events; both are present and equal, `history_id` is kept for backward compatibility).
The returned `data.id` is the `chat_id` you will use when sending messages (this is also the `chat_id` field in SSE events).

---

### List Chats

- Method: `GET /api/v1/chats`
- Headers:
  - `x-api-key: YOUR_API_KEY`
- Query parameters:
  - `limit` (int, default `50`, max `1000`)
  - `offset` (int, default `0`)
  - `order_by` (string, default `-created_at`)
    - Allowed: `"asc"`, `"ascending"`, `"desc"`, `"descending"`, `"created_at"`, `"-created_at"`.
  - `agent_id` (optional string) – filter by agent.
  - `external_user_id` (optional string) – filter chats created for a given external user.
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "pagination": {
      "limit": 20,
      "offset": 0,
      "total_records": 3,
      "current_page": 1,
      "total_pages": 1
    },
    "data": [
      {
        "id": 123,
        "name": "Support chat for user-123",
        "created_at": "2024-01-01T00:00:00Z",
        "updated_at": "2024-01-01T00:00:00Z",
        "meta": {
          "conversation_agent_id": "AGENT_UUID",
          "external_user_id": "user-123"
        },
        "external_user_id": "user-123"
      }
    ]
  }
  ```

Example:

```bash
curl "$CODEER_API_ROOT/api/v1/chats?limit=20&offset=0&external_user_id=user-123" \
  -H "x-api-key: $CODEER_API_KEY"
```

---

### List Chat Messages

Fetch the messages for a given chat history. This is useful when you want to reconstruct the conversation transcript after sending messages via SSE.

- Method: `GET /api/v1/chats/{chat_id}/messages`
- Headers:
  - `x-api-key: YOUR_API_KEY`
- Path parameters:
  - `chat_id` (int): from `Create Chat` response (same value as the `chat_id` field in SSE events).
- Query parameters:
  - `limit` (int, default `50`, max `1000`)
  - `offset` (int, default `0`)
    - Messages are ordered by `created_at` ascending (oldest → newest).
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "pagination": {
      "limit": 50,
      "offset": 0,
      "total_records": 2,
      "current_page": 1,
      "total_pages": 1
    },
    "data": [
      {
        "id": 1,
        "group_id": "cvg-11111111-1111-1111-1111-111111111111",
        "role": "system",
        "content": "",
        "meta": {
          "agent_profile": null,
          "participant_email": null,
          "reasoning_steps": null,
          "token_usage": null,
          "related_questions": null,
          "current_step_index": null,
          "response_time_ms": null
        },
        "attached_files": []
      },
      {
        "id": 2,
        "group_id": "cvg-22222222-2222-2222-2222-222222222222",
        "role": "user",
        "content": "Hello!",
        "meta": {
          "agent_profile": {
            "id": "b6a3d9c3-8b1e-4de3-9a3c-2f1b5f7b8a01",
            "name": "Support Copilot"
          },
          "participant_email": "user@example.com",
          "reasoning_steps": null,
          "token_usage": null,
          "related_questions": null,
          "current_step_index": null,
          "response_time_ms": null
        },
        "attached_files": [
          {
            "id": "c8a1e1c5-0c4b-4ac5-9e19-7f2e0ab6f3a2",
            "type": "application/pdf",
            "name": "document.pdf",
            "url": "https://.../media/...",
            "scope": "persistent",
            "attachment_type": "file"
          }
        ]
      }
    ]
  }
  ```

Notes:
- `role` is one of `"system"`, `"user"`, `"assistant"`.
- `group_id` groups a user message and its corresponding assistant response; messages with the same `group_id` belong to the same turn.
- `meta` mirrors the internal conversation metadata:
  - `agent_profile`, `participant_email`, `reasoning_steps` (when available),
  - `token_usage`, `related_questions`, `current_step_index`, `response_time_ms`, etc.
- `attached_files` contains resolved attachment info for each message (file uploads, web content, page snapshots).

Example:

```bash
curl "$CODEER_API_ROOT/api/v1/chats/$CHAT_ID/messages?limit=50&offset=0" \
  -H "x-api-key: $CODEER_API_KEY"
```

---

### Upload File for Chat

Uploads a file and returns an attachment UUID that can be referenced in chat messages.

- Method: `POST /api/v1/chats/upload-file`
- Headers:
  - `x-api-key: YOUR_API_KEY`
- Content type: `multipart/form-data`
- Fields:
  - `file` (required): the actual file upload.
  - `scope` (optional string):
    - `"persistent"` (default): file remains attached to the workspace/history.
    - `"ephemeral"`: file intended for a single conversation.
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "data": {
      "uuid": "c8a1e1c5-0c4b-4ac5-9e19-7f2e0ab6f3a2",
      "original_name": "document.pdf",
      "content_type": "application/pdf",
      "size": 123456,
      "file_url": "https://.../media/...",
      "scope": "persistent"
    }
  }
  ```
- Validation errors (e.g., too large, unsupported type) return `400` with a descriptive `message`.

Example:

```bash
curl -X POST "$CODEER_API_ROOT/api/v1/chats/upload-file" \
  -H "x-api-key: $CODEER_API_KEY" \
  -F "file=@/path/to/document.pdf" \
  -F "scope=persistent"
```

Use the returned `uuid` value in `attached_file_uuids` when sending messages.

---

### Send Message (Streaming via SSE)

This is the main chat endpoint. With `"stream": true`, it returns a Server-Sent Events (SSE) stream in a Responses-style JSON format.

- Method: `POST /api/v1/chats/{chat_id}/messages`
- Headers:
  - `Content-Type: application/json`
  - `x-api-key: YOUR_API_KEY`
- Path parameters:
  - `chat_id` (int): from `Create Chat` response.
- Query parameters (optional):
  - `external_user_id`: same as in `Create Chat`.
- Body:
  ```json
  {
    "message": "How can I reset my password?",
    "stream": true,
    "agent_id": "AGENT_UUID",
    "attached_file_uuids": [
      "c8a1e1c5-0c4b-4ac5-9e19-7f2e0ab6f3a2"
    ]
  }
  ```
  - `message` (string, required): user input.
  - `stream` (bool, default `true`): `true` for SSE streaming.
  - `agent_id` (UUID, required): must be a published agent in the same workspace.
  - `attached_file_uuids` (optional array of strings): file UUIDs from `upload-file`.
- Success (streaming):
  - `200 OK`
  - `Content-Type: text/event-stream`
  - Connection remains open until the answer finishes or a timeout/error occurs.

Example (streaming with `curl`):

```bash
curl -N -X POST "$CODEER_API_ROOT/api/v1/chats/$CHAT_ID/messages" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $CODEER_API_KEY" \
  -d '{
    "message": "Hello!",
    "stream": true,
    "agent_id": "b6a3d9c3-8b1e-4de3-9a3c-2f1b5f7b8a01",
    "attached_file_uuids": []
  }'
```

#### SSE Event Format

Each chunk in the stream is an SSE frame:

- `event: <event_name>`
- `data: <single-line JSON payload>`

The stream ends with a final sentinel line:

```text
data: [DONE]
```

You should treat `[DONE]` as “no more events”.

Currently emitted events:

1. `response.created`
   - First event after the LLM is prepared.
   - Example payload:
     ```json
     {
       "type": "response.created",
       "response_id": "9c533ea1-0e5a-4a99-a7f9-5b694e9e9a1f",
       "chat_id": 123,
       "agent_id": "b6a3d9c3-8b1e-4de3-9a3c-2f1b5f7b8a01",
       "model": "gpt-4o"
     }
     ```

2. `response.reasoning_step.start`
   - Emitted when a new reasoning/tool step starts.
   - Payload (simplified):
     ```json
     {
       "type": "response.reasoning_step.start",
       "response_id": "…",
       "chat_id": 123,
       "step": {
         "id": "step-uuid",
         "type": "search_web",
         "content": "Searching web for …",
         "args": { "query": "…" },
         "timestamp": "2024-01-01T00:00:00Z"
       }
     }
     ```

3. `response.reasoning_step.end`
   - Emitted when a reasoning/tool step completes.
   - Payload (simplified):
     ```json
     {
       "type": "response.reasoning_step.end",
       "response_id": "…",
       "chat_id": 123,
       "step": {
         "id": "step-uuid",
         "type": "search_web",
         "result": { },
         "token_usage": { },
         "timestamp": "2024-01-01T00:00:01Z"
       }
     }
     ```

4. `response.output_text.delta`
   - Emitted for each partial text chunk.
   - Payload:
     ```json
     {
       "type": "response.output_text.delta",
       "response_id": "…",
       "chat_id": 123,
       "delta": "partial text chunk"
     }
     ```

5. `response.output_text.completed`
   - Emitted once when the answer is fully generated.
   - Payload (simplified):
     ```json
     {
       "type": "response.output_text.completed",
       "response_id": "…",
       "chat_id": 123,
       "final_text": "full answer",
       "usage": {
         "total_tokens": 609,
         "total_prompt_tokens": 133,
         "total_completion_tokens": 476,
         "total_calls": 2
       }
     }
     ```

6. `response.error`
   - Emitted if something goes wrong during streaming or if the stream times out.
   - Payload:
     ```json
     {
       "type": "response.error",
       "response_id": "…",
       "chat_id": 123,
       "message": "Error message",
       "code": 10005
     }
     ```

#### SSE Client Notes

- Use an EventSource-like client (browser `EventSource`, `fetch` + streaming reader, or any SSE library).
- Subscribe to the events you care about:
  - `response.output_text.delta` for incremental rendering.
  - `response.output_text.completed` for final text and token usage.
  - `response.reasoning_step.*` to show the assistant’s “thinking”.
  - `response.error` to surface errors to the user.

---

### Send Message (Non-streaming, `stream: false`)

Use the same endpoint with `"stream": false` to get a single JSON response instead of an SSE stream.

- Method: `POST /api/v1/chats/{chat_id}/messages`
- Headers:
  - `Content-Type: application/json`
  - `x-api-key: YOUR_API_KEY`
- Path parameters:
  - `chat_id` (int): from `Create Chat` response.
- Query parameters (optional):
  - `external_user_id`: same as in `Create Chat`.
- Body:
  ```json
  {
    "message": "How can I reset my password?",
    "stream": false,
    "agent_id": "AGENT_UUID",
    "attached_file_uuids": [
      "c8a1e1c5-0c4b-4ac5-9e19-7f2e0ab6f3a2"
    ]
  }
  ```
  - Fields are the same as streaming mode; only `"stream": false` changes the behavior.

- Success (non-streaming JSON):
  - `200 OK`
  - `Content-Type: application/json`
  - Body:
    ```json
    {
      "error_code": 0,
      "message": null,
      "pagination": null,
      "data": "Full assistant answer as a single string"
    }
    ```
    - `data` is the final answer text (equivalent to `final_text` from the `response.output_text.completed` SSE event).

On validation or internal errors, the same envelope is used with non-zero `error_code` and a descriptive `message`.
- Always stop reading after you see `data: [DONE]` or the connection closes.

---

### Send Message (Non‑streaming, optional)

If you set `"stream": false` in the request body, the same endpoint returns a single JSON response instead of SSE.

- Method: `POST /api/v1/chats/{chat_id}/messages`
- Headers:
  - `Content-Type: application/json`
  - `x-api-key: YOUR_API_KEY`
- Body:
  ```json
  {
    "message": "Short question",
    "stream": false,
    "agent_id": "AGENT_UUID",
    "attached_file_uuids": []
  }
  ```
- Success: `200` with JSON:
  ```json
  {
    "error_code": 0,
    "data": "full answer text"
  }
  ```
- Token usage and reasoning/tool metadata are not included; use streaming if you need them.

---

### Error Responses

- Common HTTP status codes:
  - `401` – invalid or missing API key.
  - `403` – forbidden (e.g., file upload rejected).
  - `404` – unknown `chat_id` or `agent_id`.
  - `400` – validation error (bad payload or file).
  - `422` – unprocessable file upload.
  - `500` – server error.
- Error body (typical):
  ```json
  {
    "error_code": 1001,
    "message": "Human-readable error message",
    "data": null
  }
  ```

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

- Common HTTP errors:
  - 401: invalid API key
  - 404: chat `chat_id` or `agent_id` not found
  - 500: server error
- Web/SSE issues:
  - Ensure the API is reachable from the browser.
  - CORS must allow your origin.
  - Always serve example files via a local server when testing (not `file://`).
  - Make sure your SSE client stops on `data: [DONE]`.

---

## Agents (Optional)

- Use a specific agent:
  - Call `GET /api/v1/chats/published-agents` and copy the desired `id`.
  - Set `CODEER_DEFAULT_AGENT` to that value in your client, or pass it explicitly as `agent_id` when creating chats and sending messages.
- Per‑request override:
  - You can send different `agent_id` values for different chats or messages to route questions to different assistants.

---

## Support

- See inline comments in the example files.
- Refer to the main README: `../README.md`

**[← Back to Main Documentation](../README.md)**
