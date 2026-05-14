# AI Analytics Assistant

> **First release note:** This is the initial implementation. The architecture is designed to avoid lock-in to any specific LLM provider and to keep the plugin free of AI API complexity.

## How it works

The AI feature is split across two layers — the **plugin** provides structured data, the **app** provides the conversational AI.

### Plugin: `POST /ai/tool` endpoint

The plugin exposes a single REST endpoint at `/wp-json/rsa/v1/ai/tool`. It accepts a tool name and returns structured JSON — no LLM call happens server-side.

**Request:**
```json
{
  "tool": "overview",
  "params": { "period": "30d", "limit": 10 }
}
```

**Response:**
```json
{
  "ok": true,
  "data": {
    "tool": "overview",
    "period": "30d",
    "data": { "pageviews": 1234, "sessions": 567, ... },
    "premium": false
  }
}
```

**Free tools** (available to all authenticated users):

| Tool | Returns |
|------|---------|
| `overview` | KPI summary (pageviews, sessions, avg time, bounce rate, daily sparkline) |
| `pages` | Top pages ranked by views |
| `audience` | OS, browser, viewport, language, timezone breakdowns |
| `referrers` | Top referring domains |
| `behavior` | Time-on-page histogram, session depth, entry pages |

**Premium tools** (require active Freemius licence):

| Tool | Returns |
|------|---------|
| `campaigns` | UTM source/medium/campaign breakdown |
| `user-flow` | Miller-column path explorer data |
| `clicks` | Click element totals |
| `heatmap` | Viewport-relative click coordinates |
| `woocommerce` | Conversion funnel, top products, revenue |

All returned data is privacy-safe — IP addresses and raw User-Agent strings are never stored or returned. Session IDs are pseudonymous UUIDs. Email-shaped query parameters are stripped from page paths. Premium tool responses (clicks, heatmap, woocommerce) contain no customer name, email, address, or other PII.

### App: Bring Your Own LLM

The PWA web app and desktop app provide the conversational interface. The user configures their own AI provider (OpenAI or any OpenAI-compatible endpoint) in the app's **Install → AI Assistant Provider** settings.

When the user asks a question:
1. The app calls `POST /ai/tool` on each connected site to fetch relevant analytics data
2. The app packages the structured data into a system prompt
3. The app sends the prompt + question to the user's configured LLM
4. The LLM response is displayed in the chat UI

**No API key is stored on the server.** The AI provider key lives only in the app's `localStorage`, never sent to the WordPress site.

### WordPress Admin: Free Insights Panel

The Overview dashboard page includes a free **Insights** section (no LLM required). Insight cards are computed server-side from your analytics data — top page, bounce rate assessment, engagement ratio, top referrer, and top campaign.

## Benefits of this architecture

- **Consistent AI voice** across multiple sites — the app uses a single LLM regardless of what each site's plugin version is
- **Multi-site queries** — the app can combine data from all connected sites and ask the LLM to compare them
- **No server-side AI cost** — the plugin never makes an API call, has no API key to manage, and no LLM failures to handle
- **Privacy** — the structured data returned by `/ai/tool` contains no PII; the user's API key stays on their device
- **Offline-capable** — the desktop app can bundle a local LLM in future without any plugin changes
- **Future-proof** — adding new tools doesn't require LLM prompt engineering; the app decides what data to fetch and how to present it

## Configuration

**Plugin side:** No configuration needed. The `/ai/tool` endpoint is available automatically to authenticated users.

**App side:** In the PWA or desktop app, navigate to **Install → AI Assistant Provider** and enter:
- **API Endpoint** — OpenAI-compatible chat completions URL (default: `https://api.openai.com/v1/chat/completions`)
- **API Key** — your API key (not required for local LLMs like Ollama)
- **Model** — the model name to use (default: `gpt-4o-mini`)

## Example: Using the tool endpoint directly

```bash
# Fetch overview data (free)
curl -X POST https://yoursite.com/wp-json/rsa/v1/ai/tool \
  -H "Authorization: Basic $(echo -n 'user:app_pass' | base64)" \
  -d '{"tool":"overview","params":{"period":"7d"}}'

# Fetch campaigns data (premium)
curl -X POST https://yoursite.com/wp-json/rsa/v1/ai/tool \
  -H "Authorization: Basic $(echo -n 'user:app_pass' | base64)" \
  -d '{"tool":"campaigns","params":{"period":"30d","limit":5}}'
```

## Security & Privacy

- The `/ai/tool` endpoint is protected by the same authentication as all other REST API endpoints (WordPress Application Passwords)
- Free tools are available to any authenticated user; premium tools require an active Freemius licence
- No data from the tool endpoint is cached on external servers — it's delivered directly to the requesting app
- The app's AI provider key is stored in `localStorage` on the user's device, never transmitted to the WordPress site
- Data returned by `/ai/tool` contains no IP addresses, raw User-Agent strings, or customer PII. Session IDs are pseudonymous UUIDs. Page paths have email-shaped query parameters stripped.
