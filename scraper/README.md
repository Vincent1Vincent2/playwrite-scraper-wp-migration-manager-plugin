# **Adaptive Content-Weighted Grouping Algorithm**

## **🎯 Overview**

This algorithm intelligently extracts and groups website content by analyzing semantic relationships, visual patterns, and layout structures. It adapts its grouping strategy based on content distribution to prevent both over-grouping and under-grouping.

---

## **📋 Complete Algorithm Flow**

### **Phase 1: Content Discovery & Filtering**

#### **Step 1.1: Element Collection**

- Scans all visible DOM elements (`display !== 'none'`, `visibility !== 'hidden'`)
- Collects ~1,000-2,000 elements from typical web pages

#### **Step 1.2: Smart Filtering**

- **Cookie Filter**: Removes cookie consent dialogs (contextual - only if cookie terms appear WITH consent keywords)
- **Header/Footer Filter**: Identifies semantic containers (`<header>`, `<footer>`, `role="banner"`) and excludes entire trees
- **Conservative Approach**: Only removes obvious navigation/administrative elements

#### **Step 1.3: Content Extraction**

- **Relevance Scoring**: Elements get scores based on content value (images=10, headings=8, paragraphs=6, etc.)
- **Content Leaf Detection**: Identifies elements that contain actual content vs. wrapper containers
- **Type Classification**: Categorizes as image, video, text, or link

#### **Step 1.4: Context-Aware Duplicate Detection**

- **Structural Elements**: Allows "View case study", "Read more" buttons to repeat across cards
- **Positional Awareness**: Same text >100px apart = different contexts (allowed)
- **True Duplicates**: Only blocks identical text in same position
- **Navigation Elements**: Preserves menu items that appear multiple times

---

### **Phase 2: Adaptive Grouping Analysis**

#### **Step 2.0: Semantic Relationship Analysis** ⭐ **(NEW - Highest Priority)**

**Forced Semantic Groupings:**

- **`<a>` containers**: Everything inside a link with multiple children → 1 group
- **`<li>` containers**: List item contents → 1 group
- **Card containers**: Elements in `.card`, `.item`, `article` → 1 group
- **Post containers**: Blog post elements → 1 group

**Likelihood-Based Grouping:**

- **Shared Parent Analysis**: Elements in same semantic container get +0.6 score
- **Content Relationships**: Image + text (+0.3), text + CTA button (+0.4)
- **Visual Proximity**: <100px distance (+0.2), >300px distance (-0.3)
- **Styling Similarity**: Shared CSS classes (+0.2)
- **Threshold**: >0.7 likelihood score for grouping

#### **Step 2.1: Visual Clustering** _(Remaining Items Only)_

- **Distance Calculation**: Euclidean distance between element centers
- **Visual Gap Analysis**: Measures empty space between elements
- **Clustering**: Groups elements <300px apart with <100px visual gaps
- **Result**: 50-300 visual clusters typically

#### **Step 2.2: Layout Container Analysis** _(Remaining Items Only)_

- **Container Detection**: Finds flex/grid containers, elements with backgrounds/borders
- **Scoring System**: Flex/grid (+5), distinct background (+3), borders (+2), padding (+3)
- **Threshold Modes**: Strict (8), Moderate (5), Relaxed (3)
- **Result**: 10-50 layout-based groups typically

#### **Step 2.3: Content Pattern Recognition**

**Pattern Types Detected:**

- **Hero**: Image + heading + text + link (score: 10)
- **Card**: Image + text + link (score: 8)
- **Feature**: Heading + multiple text (score: 7)
- **Media**: Image + text (score: 6)
- **Navigation**: Multiple links (score: 5)

---

### **Phase 3: Adaptive Strategy Selection**

#### **Step 3.1: Distribution Analysis**

- **Single Items**: Count of individual elements
- **Multi-Item Groups**: Count of grouped elements
- **Average Group Size**: Total items ÷ groups

#### **Step 3.2: Strategy Selection**

- **Liberal Grouping**: Single items > Multi-groups × 3 → Threshold = 3
- **Selective Grouping**: Multi-groups > Single items → Threshold = 7
- **Moderate Grouping**: Balanced distribution → Threshold = 5

#### **Step 3.3: Scoring & Prioritization**

```
Base Score = Pattern Score (0-10)
+ Semantic Bonus (+10 for forced semantic groups)
+ High Confidence (+5 for >0.8 confidence)
+ Layout Container (+5 for flex/grid, +3 for background)
+ Content Quality (+3 heading, +2 image, +2 link)
- Over-grouping Penalty (-5 for >8 items)
```

---

### **Phase 4: Anti-Over-Grouping Correction**

#### **Step 4.1: Over-Grouping Detection**

- **Ideal Group Count**: Total items ÷ 8 (max 8 items per group)
- **Detection Trigger**: Actual groups < Ideal groups ÷ 2
- **Example**: 400 items should have ~50 groups, triggers if <25 groups

#### **Step 4.2: Large Group Breakdown**

- **Semantic Protection**: Never breaks semantic groups (preserves HTML structure)
- **Size Threshold**: Breaks groups >10 items
- **Tighter Clustering**: Re-clusters with 200px distance (vs 300px)
- **Result**: Converts 1 massive group → 50-100 reasonable groups

---

### **Phase 5: Final Processing**

#### **Step 5.1: Reading Order Sort**

- **Primary Sort**: Top position (vertical order)
- **Secondary Sort**: Left position (horizontal order within rows)
- **Threshold**: 50px tolerance for "same row"

#### **Step 5.2: Result Formatting**

**Individual Items:**

```json
{
  "type": "text|image|link|video",
  "wrapper": false,
  "text": "content",
  "element": "p",
  "class": "className"
}
```

**Grouped Items:**

```json
{
  "type": "group",
  "wrapper": true,
  "element": "div",
  "pattern": "hero|card|feature",
  "score": 8.5,
  "children": [...individual items...]
}
```

---

## **🎯 Key Algorithm Strengths**

### **1. Adaptive Intelligence**

- **Self-adjusting**: Changes strategy based on content structure
- **Context-aware**: Understands website patterns and user intent
- **Multi-layered**: Combines semantic, visual, and layout analysis

### **2. Structure Preservation**

- **Semantic-first**: Respects HTML structure and developer intent
- **Anti-over-grouping**: Prevents everything being lumped together
- **Content-aware**: Preserves card patterns, navigation, and UI elements

### **3. Robust Filtering**

- **Conservative approach**: Only removes obvious non-content
- **Duplicate intelligence**: Allows legitimate repeating elements
- **Quality scoring**: Prioritizes meaningful content

### **4. Performance Optimization**

- **Hierarchical processing**: Most important analysis first
- **Incremental refinement**: Each step works on remaining items
- **Efficient algorithms**: O(n²) complexity for grouping, manageable for web content

---

## **📊 Typical Results**

**Input**: 1,500 DOM elements
**After Filtering**: 300-500 content items  
**After Grouping**: 50-150 final groups
**Grouping Ratio**: 20-40% (balanced mix of groups vs individuals)

The algorithm successfully transforms raw HTML chaos into structured, meaningful content organization that reflects both the website's intended design and natural content relationships.

Usage Examples:
Get all URLs from a page (same domain only, unique):

http://localhost:8000/extract-urls?url=https://example.com
Get all URLs including external domains:

http://localhost:8000/extract-urls?url=https://example.com&same_domain=false
Get all URLs including duplicates:

http://localhost:8000/extract-urls?url=https://example.com&unique=false

---

## Running and Testing the API

### Local (Python)

1. Create a virtual environment and install deps:

```
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python -m playwright install --with-deps
```
or
```
py -3.11 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
python -m playwright install --with-deps
```

2. Start the server:

```
uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```
or
```
uvicorn app:app --host 0.0.0.0 --port 8000
```

3. Try endpoints:

- Health: `curl http://localhost:8000/`
- Scrape (JSON POST):
  ```bash
  curl -X POST "http://localhost:8000/scrape" \
       -H "Content-Type: application/json" \
       -d '{
         "url": "https://example.com",
         "ai": {
           "enabled": false
         }
       }' | jq .
  ```
- Extract URLs: `curl "http://localhost:8000/extract-urls?url=https://example.com" | jq .`
- AI test (optional):
  ```bash
  curl -X POST "http://localhost:8000/ai-test" \
       -H "Content-Type: application/json" \
       -d '{
         "ai": {
           "provider": "anthropic",
           "api_key": "YOUR_KEY",
           "model": "claude-haiku-4-5"
         }
       }' | jq .
  ```

### Local (Docker)

From this directory:

```
docker build -t scraper:latest .
docker run --rm -it -p 8000:8000 --cap-drop=ALL --security-opt=no-new-privileges scraper:latest
```

### Visualize results

Open `index.html` in a browser and paste the JSON returned by `/scrape`.

---

## Server Deployment (Hardened)

### Minimal secure run command

```
docker run -d \
  --name scraper \
  -p 127.0.0.1:8000:8000 \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=128m \
  --cap-drop=ALL \
  --security-opt no-new-privileges \
  --restart unless-stopped \
  scraper:latest
```

Put behind an HTTPS reverse proxy (Nginx/Traefik) with rate limiting and optional IP allowlists or auth.

### Environment requirements

- Python 3.11 (if not using Docker)
- Playwright 1.40.0 and Chromium (installed by `playwright install --with-deps`)
- Linux/macOS with required Chromium system libraries (see Dockerfile for a reference list)
- 1GB RAM recommended for complex pages

### Security guidance

- Do not expose Uvicorn directly to the internet. Bind to loopback and front with an HTTPS proxy
- Drop all Linux capabilities and forbid privilege escalation
- Run with read-only filesystem and a tmpfs for `/tmp`
- Add proxy-level rate limits and timeouts; consider API keys/allowlists
- Log and monitor errors/timeouts; respect robots.txt and site terms

---

## AI Integration Overview

### Request structure

The `/scrape` endpoint now accepts a JSON body of the form:

```json
{
  "url": "https://example.com",
  "ai": {
    "enabled": true,
    "provider": "openai | anthropic | none",
    "api_key": "YOUR_KEY",
    "model": "optional-model-name"
  }
}
```

- If `ai.enabled` is `false` or `ai` is omitted, the scraper only performs heuristic grouping.
- If `ai.enabled` is `true` and a supported `provider`/`api_key` are supplied, the backend will:
  - Build section-level groups in Python.
  - Call the selected provider (OpenAI / Anthropic) to **label** sections (`hero`, `services`, `testimonials`, `faq`, `contact`, `footer`, `other`) and optionally **reorder** them.
  - Never drop or duplicate sections – only change order and `pattern` labels.

### Providers

- **OpenAI**
  - Requires `openai` in `requirements.txt` and a valid `api_key`.
  - Uses `OPENAI_MODEL` env var or the `ai.model` field, defaulting to `gpt-4.1-mini` if not provided.
- **Anthropic**
  - Requires `anthropic` in `requirements.txt` and a valid `api_key`.
  - Uses `ANTHROPIC_MODEL` env var or the `ai.model` field, defaulting to `claude-haiku-4-5` if not provided.

### Testing AI connectivity

Use the `/ai-test` endpoint with your desired provider/model:

```bash
curl -X POST "http://localhost:8000/ai-test" \
     -H "Content-Type: application/json" \
     -d '{
       "ai": {
         "provider": "openai",
         "api_key": "YOUR_OPENAI_KEY",
         "model": "gpt-4.1-mini"
       }
     }' | jq .
```

The test sends the prompt `"Are you ready?"` and returns either the model's reply or a detailed error to help diagnose configuration issues.

---

## Notes

- The Playwright browser is launched headless with `--no-sandbox --disable-dev-shm-usage` (see `app.py`). When containerizing, prefer `--cap-drop=ALL` and an unprivileged user.
- For high throughput, scale horizontally behind the proxy. Avoid sharing one browser instance across too many concurrent requests.
