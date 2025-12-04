from fastapi import FastAPI, HTTPException
from playwright.async_api import async_playwright
import asyncio
import logging

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Web Scraper API", version="1.0.0")

# Global browser instance
browser = None
playwright_instance = None

@app.on_event("startup")
async def startup_event():
    """Initialize Playwright browser on startup"""
    global browser, playwright_instance
    try:
        playwright_instance = await async_playwright().start()
        browser = await playwright_instance.chromium.launch(
            headless=True,
            args=['--no-sandbox', '--disable-dev-shm-usage']
        )
        logger.info("Browser initialized successfully")
    except Exception as e:
        logger.error(f"Failed to initialize browser: {e}")
        raise

@app.on_event("shutdown")
async def shutdown_event():
    """Clean up browser on shutdown"""
    global browser, playwright_instance
    if browser:
        await browser.close()
    if playwright_instance:
        await playwright_instance.stop()
    logger.info("Browser closed successfully")

@app.get("/")
async def root():
    """Health check endpoint"""
    return {"status": "ok", "message": "Web Scraper API is running"}

@app.get("/scrape")
async def scrape_url(url: str):
    """
    Scrape a URL using adaptive content-weighted grouping algorithm
    Usage: http://localhost:8000/scrape?url=https://example.com
    """
    if not browser:
        raise HTTPException(status_code=500, detail="Browser not initialized")
    
    if not url:
        raise HTTPException(status_code=400, detail="URL parameter is required")
    
    try:
        # Create a new page for this request
        page = await browser.new_page()
        
        # Navigate to the URL
        await page.goto(url, wait_until="networkidle")
        
        # Handle cookie consent dialogs
        try:
            await asyncio.sleep(3)
            
            cookie_selectors = [
                '[id*="cookie"] button:has-text("Accept")',
                '[id*="cookie"] button:has-text("Acceptera")',
                '[id*="cookie"] button:has-text("OK")',
                '[class*="cookie"] button:has-text("Accept")',
                '#CybotCookiebotDialogBodyButtonAccept',
                '[role="dialog"] button:has-text("Accept")',
                'button:has-text("Acceptera alla")',
                'button[data-cc-btn="accept"]'
            ]
            
            for selector in cookie_selectors:
                try:
                    if await page.locator(selector).first.is_visible(timeout=2000):
                        await page.locator(selector).first.click(timeout=2000)
                        logger.info(f"Clicked cookie consent button: {selector}")
                        await asyncio.sleep(2)
                        break
                except:
                    continue
                    
            await asyncio.sleep(2)
            
        except Exception as e:
            logger.info(f"Cookie dialog handling: {e}")
        
        # Execute the adaptive content-weighted grouping algorithm
        result = await page.evaluate("""
          (function() {
              // === DEBUG LOGGING SYSTEM ===
            const debugLog = [];
            function log(message, data = null) {
              const entry = { message };
              if (data !== null) {
                entry.data = data;
              }
              debugLog.push(entry);
              console.log(message, data || '');
            }
            
            // === UTILITY FUNCTIONS ===
            function isVisible(el) {
              if (!el) return false;
              const style = window.getComputedStyle(el);
              return style && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
            }

            function isCookieRelatedElement(el) {
              const className = (el.className && el.className.toString ? el.className.toString() : el.className || '');
              const id = el.id || '';
              const text = el.textContent ? el.textContent.toLowerCase() : '';
              
              const cookiePatterns = [
                'cookie', 'cookiebot', 'cybot', 'gdpr', 'consent', 'privacy-policy',
                'skip-link', 'screen-reader-text'
              ];
              
              for (const pattern of cookiePatterns) {
                if (className.toLowerCase().includes(pattern) || id.toLowerCase().includes(pattern)) {
                  return true;
                }
              }
              
              // More specific consent terms - only match if they appear in cookie context
              const specificCookieTerms = ['samtycke', 'cookiebot', 'maximal lagringstid'];
              for (const term of specificCookieTerms) {
                if (text.includes(term)) return true;
              }
              
              // Only match "godkänn/acceptera" if it's clearly in cookie context
              if ((text.includes('godkänn') || text.includes('acceptera')) && 
                  (text.includes('cookie') || text.includes('samtycke') || text.includes('gdpr'))) {
                return true;
              }
              
              return false;
            }

            function isHeaderElement(el) {
              const tag = el.tagName.toLowerCase();
              const className = (el.className && el.className.toString ? el.className.toString() : el.className || '');
              const id = el.id || '';
              const role = el.getAttribute('role') || '';
              
              // Only match semantic headers and very obvious patterns
              if (tag === 'header' || role === 'navigation' || role === 'banner') {
                return true;
              }
              
              // Very specific header patterns only
              const headerPatterns = ['site-header', 'main-header', 'page-header', 'header-main'];
              for (const pattern of headerPatterns) {
                if (className.toLowerCase() === pattern || id.toLowerCase() === pattern) {
                  return true;
                }
              }
              
              return false;
            }

            function isFooterElement(el) {
              const tag = el.tagName.toLowerCase();
              const className = (el.className && el.className.toString ? el.className.toString() : el.className || '');
              const id = el.id || '';
              const role = el.getAttribute('role') || '';
              
              // Only match semantic footers and very obvious patterns
              if (tag === 'footer' || role === 'contentinfo') {
                return true;
              }
              
              // Very specific footer patterns only
              const footerPatterns = ['site-footer', 'main-footer', 'page-footer', 'footer-main'];
              for (const pattern of footerPatterns) {
                if (className.toLowerCase() === pattern || id.toLowerCase() === pattern) {
                  return true;
                }
              }
              
              return false;
            }

            // === CONTENT RELEVANCE SCORING ===
            function getContentRelevanceScore(el) {
              const tag = el.tagName.toLowerCase();
              let score = 0;
              
              // High value content
              if (tag === 'img' && el.src) score += 10;
              if (tag === 'video' && el.src) score += 10;
              if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag)) score += 8;
              if (tag === 'p' && el.textContent.trim().length > 2) score += 6;
              if (tag === 'a' && el.href && el.textContent.trim()) score += 5;
              
              // Medium value content
              if (['span', 'div'].includes(tag) && el.textContent.trim().length > 2) score += 3;
              if (tag === 'button' && el.textContent.trim()) score += 4;
              if (['ul', 'ol', 'li'].includes(tag) && el.textContent.trim()) score += 3;
              
              // Background images add value
              const computedStyle = window.getComputedStyle(el);
              if (computedStyle.backgroundImage && computedStyle.backgroundImage !== 'none') {
                score += 8;
              }
              
              // Penalize empty or wrapper-only elements
              if (el.textContent.trim().length === 0 && !el.querySelector('img, video')) {
                score = Math.max(0, score - 5);
              }
              
              return score;
            }

            function isContentBearing(el) {
              return getContentRelevanceScore(el) > 0;
            }

            function hasDirectTextContent(el) {
              return Array.from(el.childNodes)
                .some(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0);
            }

            function getDirectText(el) {
              return Array.from(el.childNodes)
                .filter(node => node.nodeType === Node.TEXT_NODE)
                .map(node => node.textContent.trim())
                .filter(text => text.length > 0)
                .join(' ');
            }

            function isContentLeaf(el) {
              // Check if element is a content leaf (has direct text or is an img/video)
              const tag = el.tagName.toLowerCase();
              
              if (['img', 'video', 'audio'].includes(tag)) return true;
              if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button'].includes(tag)) return true;
              
              // Also include divs with direct text content (for complex structures)
              if (tag === 'div' && hasDirectTextContent(el)) return true;
              
              return hasDirectTextContent(el);
            }

            function extractContentData(el) {
              const tag = el.tagName.toLowerCase();
              const relevanceScore = getContentRelevanceScore(el);
              
              if (relevanceScore === 0) return null;
              
              // Only extract from content leaves, not large containers
              if (!isContentLeaf(el) && !['img', 'video'].includes(tag)) {
                return null;
              }
              
              let item = {
                element: el,
                tag: tag,
                relevanceScore: relevanceScore,
                path: getElementPath(el)
              };
              
              // Extract specific content types
              if (tag === 'img' && el.src) {
                item.type = 'image';
                item.url = el.src;
                item.alt = el.alt || '';
              } else if (tag === 'video' && el.src) {
                item.type = 'video';
                item.url = el.src;
                item.poster = el.poster || '';
              } else if (tag === 'a' && el.href) {
                item.type = 'link';
                item.url = el.href.startsWith('/') ? window.location.origin + el.href : el.href;
                item.text = el.textContent.trim();
              } else if (hasDirectTextContent(el)) {
                // Only get direct text content, not nested content
                item.type = 'text';
                item.text = getDirectText(el);
                if (item.text.length === 0) return null;
              } else if (el.textContent.trim() && ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'].includes(tag)) {
                // For semantic text elements, get full text but only if reasonable length
                const text = el.textContent.trim();
                if (text.length < 500) {
                  item.type = 'text';
                  item.text = text;
                } else {
                  return null;
                }
              } else {
                // Check for background images
                const computedStyle = window.getComputedStyle(el);
                const bgImage = computedStyle.backgroundImage;
                if (bgImage && bgImage !== 'none') {
                  const urlMatch = bgImage.match(/url\\(["']?([^"')]+)["']?\\)/);
                  if (urlMatch && urlMatch[1]) {
                    item.type = 'image';
                    item.url = urlMatch[1];
                    item.source = 'css_background';
                  }
                }
              }
              
              // Add styling info
              if (el.className) item.class = el.className;
              if (el.id) item.id = el.id;
              
              return item;
            }

            function getElementPath(el) {
              const path = [];
              let current = el;
              
              while (current && current !== document.body) {
                path.unshift({
                  element: current,
                  tag: current.tagName.toLowerCase(),
                  contentScore: getContentRelevanceScore(current),
                  isContentBearing: isContentBearing(current)
                });
                current = current.parentElement;
              }
              
              return path;
            }

            // === CSS LAYOUT ANALYSIS ===
            function getCSSLayoutInfo(el) {
              const computedStyle = window.getComputedStyle(el);
              const rect = el.getBoundingClientRect();
              
              return {
                display: computedStyle.display,
                position: computedStyle.position,
                flexDirection: computedStyle.flexDirection,
                gridTemplateColumns: computedStyle.gridTemplateColumns,
                backgroundColor: computedStyle.backgroundColor,
                border: computedStyle.border,
                borderRadius: computedStyle.borderRadius,
                margin: {
                  top: parseFloat(computedStyle.marginTop) || 0,
                  right: parseFloat(computedStyle.marginRight) || 0,
                  bottom: parseFloat(computedStyle.marginBottom) || 0,
                  left: parseFloat(computedStyle.marginLeft) || 0
                },
                padding: {
                  top: parseFloat(computedStyle.paddingTop) || 0,
                  right: parseFloat(computedStyle.paddingRight) || 0,
                  bottom: parseFloat(computedStyle.paddingBottom) || 0,
                  left: parseFloat(computedStyle.paddingLeft) || 0
                },
                rect: {
                  top: rect.top,
                  left: rect.left,
                  width: rect.width,
                  height: rect.height,
                  bottom: rect.bottom,
                  right: rect.right
                }
              };
            }

            function isLayoutContainer(el, filterMode = 'moderate') {
              const layout = getCSSLayoutInfo(el);
              let score = 0;
              
              // Flex/Grid containers are likely layout containers
              if (layout.display.includes('flex') || layout.display.includes('grid')) {
                score += 5;
              }
              
              // Elements with distinct backgrounds are likely sections
              if (layout.backgroundColor && layout.backgroundColor !== 'rgba(0, 0, 0, 0)' && layout.backgroundColor !== 'transparent') {
                score += 3;
              }
              
              // Elements with borders/border-radius suggest content cards
              if (layout.border !== 'none' || parseFloat(layout.borderRadius) > 0) {
                score += 2;
              }
              
              // Elements with significant margins create visual separation
              const totalMargin = layout.margin.top + layout.margin.bottom;
              if (totalMargin > 20) {
                score += 2;
              }
              
              // Large containers with padding suggest content areas
              if (layout.rect.width > 200 && layout.rect.height > 100 && 
                  (layout.padding.top + layout.padding.bottom) > 20) {
                score += 3;
              }
              
              // Apply filter based on mode
              const thresholds = {
                'strict': 8,    // Only very obvious layout containers
                'moderate': 5,  // Reasonably confident containers
                'relaxed': 3    // More inclusive
              };
              
              return score >= (thresholds[filterMode] || thresholds['moderate']);
            }

            // === CONTENT PATTERN ANALYSIS ===
            function identifyContentPattern(contentItems) {
              const types = contentItems.map(item => item.type);
              const elements = contentItems.map(item => item.tag);
              
              // Check for heading patterns (h1-h6 suggest important content sections)
              const hasHeading = elements.some(el => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(el));
              const hasImage = types.includes('image');
              const hasLink = types.includes('link');
              const textCount = types.filter(t => t === 'text').length;
              
              let patternScore = 0;
              let patternType = 'generic';
              
              // Hero pattern: image + heading + text + link
              if (hasImage && hasHeading && hasLink && textCount >= 2) {
                patternScore = 10;
                patternType = 'hero';
              }
              // Card pattern: image + text + link
              else if (hasImage && hasLink && textCount >= 1) {
                patternScore = 8;
                patternType = 'card';
              }
              // Feature pattern: heading + multiple text
              else if (hasHeading && textCount >= 2) {
                patternScore = 7;
                patternType = 'feature';
              }
              // Media pattern: image + text
              else if (hasImage && textCount >= 1) {
                patternScore = 6;
                patternType = 'media';
              }
              // Navigation pattern: multiple links
              else if (types.filter(t => t === 'link').length >= 3) {
                patternScore = 5;
                patternType = 'navigation';
              }
              
              return { patternType, patternScore, hasHeading, hasImage, hasLink, textCount };
            }

            // === VISUAL CLUSTERING ANALYSIS ===
            function calculateVisualDistance(item1, item2) {
              const rect1 = item1.element.getBoundingClientRect();
              const rect2 = item2.element.getBoundingClientRect();
              
              // Calculate center points
              const center1 = {
                x: rect1.left + rect1.width / 2,
                y: rect1.top + rect1.height / 2
              };
              const center2 = {
                x: rect2.left + rect2.width / 2,
                y: rect2.top + rect2.height / 2
              };
              
              // Euclidean distance
              const distance = Math.sqrt(
                Math.pow(center2.x - center1.x, 2) + Math.pow(center2.y - center1.y, 2)
              );
              
              // Check for visual gaps (empty space between elements)
              const horizontalGap = Math.abs(rect1.right - rect2.left) + Math.abs(rect2.right - rect1.left);
              const verticalGap = Math.abs(rect1.bottom - rect2.top) + Math.abs(rect2.bottom - rect1.top);
              const minGap = Math.min(horizontalGap, verticalGap);
              
              return {
                distance,
                visualGap: minGap,
                isVisuallyClose: distance < 300 && minGap < 100
              };
            }

            function findVisualClusters(contentItems, distanceThreshold = 300) {
              const clusters = [];
              const processed = new Set();
              
              for (const item of contentItems) {
                if (processed.has(item)) continue;
                
                const cluster = [item];
                processed.add(item);
                
                // Find visually close items
                for (const otherItem of contentItems) {
                  if (processed.has(otherItem) || item === otherItem) continue;
                  
                  const visualDist = calculateVisualDistance(item, otherItem);
                  if (visualDist.distance < distanceThreshold && visualDist.visualGap < 100) {
                    cluster.push(otherItem);
                    processed.add(otherItem);
                  }
                }
                
                if (cluster.length > 0) {
                  clusters.push(cluster);
                }
              }
              
              return clusters;
            }

            // === SEMANTIC GROUPING ANALYSIS ===
            function analyzeSemanticRelationships(contentItems) {
              log('=== SEMANTIC RELATIONSHIP ANALYSIS ===');
              
              const semanticGroups = [];
              const processedItems = new Set();
              
              // Step 1: Identify forced groupings (elements that MUST be grouped)
              log('Step 1: Identifying forced semantic groupings');
              
              for (const item of contentItems) {
                if (processedItems.has(item)) continue;
                
                const element = item.element;
                const group = [item];
                
                // Check if this element is inside a semantic container that forces grouping
                const semanticContainer = findSemanticContainer(element);
                
                if (semanticContainer) {
                  log('Found semantic container: ' + semanticContainer.tagName + '.' + (semanticContainer.className || 'no-class'));
                  
                  // Find all content items that are children of this container
                  const siblingItems = contentItems.filter(otherItem => 
                    !processedItems.has(otherItem) && 
                    otherItem !== item &&
                    semanticContainer.contains(otherItem.element)
                  );
                  
                  if (siblingItems.length > 0) {
                    group.push(...siblingItems);
                    log('  Forced grouping: ' + group.length + ' items in ' + semanticContainer.tagName);
                    
                    // Mark all items as processed
                    group.forEach(groupItem => processedItems.add(groupItem));
                    
                    semanticGroups.push({
                      items: group,
                      type: 'semantic',
                      container: semanticContainer,
                      confidence: 1.0, // 100% confidence for semantic groupings
                      reason: semanticContainer.tagName + ' container'
                    });
                    continue;
                  }
                }
                
                // If no semantic container found, mark as processed for now
                processedItems.add(item);
              }
              
              // Step 2: Calculate likelihood scores for remaining ungrouped items
              log('Step 2: Calculating grouping likelihood scores');
              
              const ungroupedItems = contentItems.filter(item => !processedItems.has(item));
              const likelihoodGroups = [];
              
              for (let i = 0; i < ungroupedItems.length; i++) {
                const item1 = ungroupedItems[i];
                if (processedItems.has(item1)) continue;
                
                const candidateGroup = [item1];
                processedItems.add(item1);
                
                // Find items with high likelihood of belonging together
                for (let j = i + 1; j < ungroupedItems.length; j++) {
                  const item2 = ungroupedItems[j];
                  if (processedItems.has(item2)) continue;
                  
                  const likelihood = calculateGroupingLikelihood(item1, item2);
                  
                  if (likelihood.score > 0.7) { // High confidence threshold
                    candidateGroup.push(item2);
                    processedItems.add(item2);
                    log('  High likelihood grouping: ' + likelihood.score.toFixed(2) + ' (' + likelihood.reasons.join(', ') + ')');
                  }
                }
                
                if (candidateGroup.length > 1) {
                  likelihoodGroups.push({
                    items: candidateGroup,
                    type: 'likelihood',
                    confidence: 0.8,
                    reason: 'high semantic likelihood'
                  });
                } else {
                  // Return single item to ungrouped pool
                  processedItems.delete(item1);
                }
              }
              
              log('Semantic analysis results:');
              log('  Forced semantic groups: ' + semanticGroups.length);
              log('  Likelihood-based groups: ' + likelihoodGroups.length);
              
              return [...semanticGroups, ...likelihoodGroups];
            }
            
            function findSemanticContainer(element) {
              let current = element.parentElement;
              
              while (current && current !== document.body) {
                const tag = current.tagName.toLowerCase();
                const className = current.className || '';
                
                // Strong semantic containers that should force grouping
                if (tag === 'a' && current.children.length > 1) {
                  return current; // Links with multiple children
                }
                
                if (tag === 'li' && current.children.length > 1) {
                  return current; // List items with multiple children
                }
                
                // Card-like containers
                if (className.toLowerCase().includes('card') && current.children.length > 1) {
                  return current;
                }
                
                // Item containers
                if (className.toLowerCase().includes('item') && current.children.length > 1) {
                  return current;
                }
                
                // Post/article containers
                if ((tag === 'article' || className.toLowerCase().includes('post')) && current.children.length > 1) {
                  return current;
                }
                
                current = current.parentElement;
              }
              
              return null;
            }
            
            function calculateGroupingLikelihood(item1, item2) {
              const reasons = [];
              let score = 0;
              
              const el1 = item1.element;
              const el2 = item2.element;
              
              // Check shared parent proximity
              const sharedParent = findNearestSharedParent(el1, el2);
              if (sharedParent) {
                const parentTag = sharedParent.tagName.toLowerCase();
                const parentClass = sharedParent.className || '';
                
                // High score for semantic parent containers
                if (['section', 'article', 'div'].includes(parentTag)) {
                  if (parentClass.toLowerCase().includes('card') ||
                      parentClass.toLowerCase().includes('item') ||
                      parentClass.toLowerCase().includes('post') ||
                      parentClass.toLowerCase().includes('product')) {
                    score += 0.6;
                    reasons.push('shared semantic container');
                  } else if (sharedParent.children.length <= 5) { // Small container
                    score += 0.4;
                    reasons.push('shared small container');
                  }
                }
              }
              
              // Check content type relationships
              if (item1.type === 'image' && item2.type === 'text') {
                score += 0.3;
                reasons.push('image-text pair');
              }
              
              if (item1.type === 'text' && item2.type === 'link' && 
                  item2.text && (item2.text.toLowerCase().includes('read') || 
                                 item2.text.toLowerCase().includes('view') ||
                                 item2.text.toLowerCase().includes('more'))) {
                score += 0.4;
                reasons.push('text-cta pair');
              }
              
              // Check visual proximity
              const rect1 = el1.getBoundingClientRect();
              const rect2 = el2.getBoundingClientRect();
              const distance = Math.sqrt(
                Math.pow(rect2.left - rect1.left, 2) + 
                Math.pow(rect2.top - rect1.top, 2)
              );
              
              if (distance < 100) {
                score += 0.2;
                reasons.push('close proximity');
              } else if (distance > 300) {
                score -= 0.3;
                reasons.push('distant positioning');
              }
              
              // Check similar styling/classes
              const class1 = item1.class || '';
              const class2 = item2.class || '';
              
              if (class1 && class2) {
                const commonClasses = class1.split(' ').filter(cls => 
                  class2.split(' ').includes(cls)
                );
                if (commonClasses.length > 0) {
                  score += 0.2;
                  reasons.push('shared styling');
                }
              }
              
              return { score: Math.max(0, Math.min(1, score)), reasons };
            }
            
            function findNearestSharedParent(el1, el2) {
              let current1 = el1.parentElement;
              
              while (current1 && current1 !== document.body) {
                let current2 = el2.parentElement;
                
                while (current2 && current2 !== document.body) {
                  if (current1 === current2) {
                    return current1;
                  }
                  current2 = current2.parentElement;
                }
                
                current1 = current1.parentElement;
              }
              
              return null;
            }

            // === ADAPTIVE GROUPING ALGORITHM ===
            function adaptiveGrouping(contentItems) {
              if (contentItems.length === 0) return [];
              
              log('=== ADAPTIVE GROUPING ANALYSIS ===');
              
              // Step 0: Semantic Analysis (NEW - highest priority)
              log('Step 0: Semantic Relationship Analysis');
              const semanticGroups = analyzeSemanticRelationships(contentItems);
              
              // Get items that were NOT grouped semantically
              const semanticallyGroupedItems = new Set();
              semanticGroups.forEach(group => {
                group.items.forEach(item => semanticallyGroupedItems.add(item));
              });
              
              const remainingItems = contentItems.filter(item => !semanticallyGroupedItems.has(item));
              log('Items after semantic grouping: ' + remainingItems.length + ' remaining, ' + semanticGroups.length + ' semantic groups created');
              
              // Step 1: Visual Clustering (for remaining items)
              log('Step 1: Visual Clustering Analysis (remaining items)');
              const visualClusters = remainingItems.length > 0 ? findVisualClusters(remainingItems) : [];
              log('Found ' + visualClusters.length + ' visual clusters from remaining items');
              
              // Step 2: Layout Container Analysis (for remaining items)
              log('Step 2: Layout Container Analysis (remaining items)');
              const layoutGroups = [];
              
              if (remainingItems.length > 0) {
                const allContainers = Array.from(document.querySelectorAll('*'))
                  .filter(el => isVisible(el) && isLayoutContainer(el) && !isInsideHeaderOrFooter(el));
                
                log('Found ' + allContainers.length + ' potential layout containers');
                
                for (const container of allContainers) {
                  const containerContent = remainingItems.filter(item => container.contains(item.element));
                  if (containerContent.length >= 2) {
                    const layout = getCSSLayoutInfo(container);
                    layoutGroups.push({
                      items: containerContent,
                      layout,
                      isFlexGrid: layout.display.includes('flex') || layout.display.includes('grid'),
                      type: 'layout'
                    });
                  }
                }
                
                log('Created ' + layoutGroups.length + ' layout-based groups');
              }
              
              // Step 3: Combine all group types
              log('Step 3: Combining all group types');
              
              // Convert semantic groups to standard format
              const formattedSemanticGroups = semanticGroups.map(group => ({
                items: group.items,
                pattern: identifyContentPattern(group.items),
                container: group.container,
                confidence: group.confidence,
                type: group.type,
                reason: group.reason
              }));
              
              // Convert visual clusters to standard format  
              const formattedVisualGroups = visualClusters.map(items => ({
                items: items,
                pattern: identifyContentPattern(items),
                type: 'visual'
              }));
              
              // Convert layout groups (already in standard format)
              const formattedLayoutGroups = layoutGroups.map(group => ({
                ...group,
                pattern: identifyContentPattern(group.items)
              }));
              
              // Combine all candidate groups
              const allCandidateGroups = [
                ...formattedSemanticGroups,
                ...formattedVisualGroups,
                ...formattedLayoutGroups
              ];
              
              log('Total candidate groups: ' + allCandidateGroups.length + ' (semantic: ' + formattedSemanticGroups.length + ', visual: ' + formattedVisualGroups.length + ', layout: ' + formattedLayoutGroups.length + ')');
              
              // Step 4: Adaptive Scoring
              log('Step 4: Adaptive Scoring');
              
              // Calculate distribution for adaptive threshold
              const singleItemGroups = allCandidateGroups.filter(g => g.items.length === 1).length;
              const multiItemGroups = allCandidateGroups.filter(g => g.items.length > 1).length;
              const avgGroupSize = allCandidateGroups.length > 0 ? 
                allCandidateGroups.reduce((sum, g) => sum + g.items.length, 0) / allCandidateGroups.length : 0;
              
              log('Distribution analysis:');
              log('  Single item groups: ' + singleItemGroups);
              log('  Multi item groups: ' + multiItemGroups);
              log('  Average group size: ' + avgGroupSize.toFixed(1));
              
              // Adaptive threshold based on content distribution
              let scoreThreshold = 5; // default
              
              if (singleItemGroups > multiItemGroups * 3) {
                scoreThreshold = 3;
                log('  Strategy: Liberal grouping (many single items detected)');
              } else if (multiItemGroups > singleItemGroups) {
                scoreThreshold = 7;
                log('  Strategy: Selective grouping (natural groups detected)');
              } else {
                log('  Strategy: Moderate grouping (balanced distribution)');
              }
              
              // Score all groups
              const scoredGroups = allCandidateGroups.map(group => {
                let score = group.pattern.patternScore;
                
                // Semantic groups get highest priority
                if (group.type === 'semantic') {
                  score += 10; // Always include semantic groups
                  log('  Semantic group bonus: +10 points');
                }
                
                // High confidence groups get bonus
                if (group.confidence && group.confidence > 0.8) {
                  score += 5;
                }
                
                // Layout container bonus
                if (group.container && group.isFlexGrid) score += 5;
                if (group.layout && (group.layout.backgroundColor !== 'rgba(0, 0, 0, 0)')) score += 3;
                
                // Content quality bonus
                if (group.pattern.hasHeading) score += 3;
                if (group.pattern.hasImage) score += 2;
                if (group.pattern.hasLink) score += 2;
                
                // Size penalty for over-grouping
                if (group.items.length > 8) score -= 5;
                
                return { ...group, score };
              });
              
              // Sort by score and remove overlapping groups
              scoredGroups.sort((a, b) => b.score - a.score);
              
              const finalGroups = [];
              const usedItems = new Set();
              
              for (const group of scoredGroups) {
                const hasOverlap = group.items.some(item => usedItems.has(item));
                if (hasOverlap) continue;
                
                if (group.score >= scoreThreshold) {
                  finalGroups.push(group);
                  group.items.forEach(item => usedItems.add(item));
                }
              }
              
              // Add remaining ungrouped items as individuals
              const ungroupedItems = contentItems.filter(item => !usedItems.has(item));
              for (const item of ungroupedItems) {
                finalGroups.push({ items: [item], score: 1, pattern: { patternType: 'individual' } });
              }
              
              // Anti-over-grouping check
              const totalContentItems = contentItems.length;
              const groupCount = finalGroups.filter(g => g.items.length > 1).length;
              const individualCount = finalGroups.filter(g => g.items.length === 1).length;
              
              log('Pre-adjustment results:');
              log('  Total content items: ' + totalContentItems);
              log('  Total groups: ' + finalGroups.length);
              log('  Multi-item groups: ' + groupCount);
              log('  Individual items: ' + individualCount);
              
              // Detect over-grouping: if we have very few groups relative to content items
              const idealGroupCount = Math.max(3, Math.floor(totalContentItems / 8)); // Aim for ~8 items per group max
              const actualGroupCount = finalGroups.length;
              
              log('Over-grouping analysis:');
              log('  Ideal group count: ~' + idealGroupCount);
              log('  Actual group count: ' + actualGroupCount);
              
              if (actualGroupCount < idealGroupCount / 2) {
                log('🚨 OVER-GROUPING DETECTED! Breaking down large groups...');
                
                // Break down the largest groups
                const adjustedGroups = [];
                
                for (const group of finalGroups) {
                  if (group.items.length > 10) {
                    log('  Breaking down group with ' + group.items.length + ' items');
                    
                    // Split large group into smaller visual clusters
                    const subClusters = findVisualClusters(group.items, 200); // Tighter clustering
                    
                    for (const cluster of subClusters) {
                      if (cluster.length > 1) {
                        adjustedGroups.push({
                          items: cluster,
                          pattern: identifyContentPattern(cluster),
                          score: 5,
                          container: null
                        });
                      } else {
                        adjustedGroups.push({
                          items: cluster,
                          pattern: { patternType: 'individual' },
                          score: 1
                        });
                      }
                    }
                  } else {
                    adjustedGroups.push(group);
                  }
                }
                
                // Update finalGroups with adjusted groups
                const newFinalGroups = adjustedGroups;
                const newGroupCount = newFinalGroups.filter(g => g.items.length > 1).length;
                const newIndividualCount = newFinalGroups.filter(g => g.items.length === 1).length;
                
                log('Post-adjustment results:');
                log('  Total groups: ' + newFinalGroups.length);
                log('  Multi-item groups: ' + newGroupCount);
                log('  Individual items: ' + newIndividualCount);
                log('  Grouping ratio: ' + (newGroupCount / (newGroupCount + newIndividualCount) * 100).toFixed(1) + '%');
                
                return newFinalGroups;
              }
              
              // Final statistics
              // Final statistics (for normal cases)
              const finalGroupCount = finalGroups.filter(g => g.items.length > 1).length;
              const finalIndividualCount = finalGroups.filter(g => g.items.length === 1).length;
              
              log('Final grouping results:');
              log('  Total groups: ' + finalGroups.length);
              log('  Multi-item groups: ' + finalGroupCount);
              log('  Individual items: ' + finalIndividualCount);
              log('  Grouping ratio: ' + (finalGroupCount / (finalGroupCount + finalIndividualCount) * 100).toFixed(1) + '%');
              
              // Sort by visual reading order
              finalGroups.sort((groupA, groupB) => {
                const rectA = groupA.items[0].element.getBoundingClientRect();
                const rectB = groupB.items[0].element.getBoundingClientRect();
                
                const topDiff = rectA.top - rectB.top;
                if (Math.abs(topDiff) > 50) {
                  return topDiff;
                }
                
                return rectA.left - rectB.left;
              });
              
              return finalGroups;
            }

            // === MAIN EXTRACTION LOGIC ===
            
            // Phase 1: Discover all relevant content
            log('Phase 1: Content Discovery');
            
            // Debug: Count filtered elements  
            const allVisibleElements = Array.from(document.body.querySelectorAll('*')).filter(el => isVisible(el));
            const cookieElements = allVisibleElements.filter(el => isCookieRelatedElement(el));
            
            // Find all header and footer container elements first
            const headerContainers = allVisibleElements.filter(el => isHeaderElement(el));
            const footerContainers = allVisibleElements.filter(el => isFooterElement(el));
            
            // Function to check if element is inside a header or footer container
            function isInsideHeaderOrFooter(el) {
              let current = el;
              while (current && current !== document.body) {
                if (headerContainers.includes(current) || footerContainers.includes(current)) {
                  return true;
                }
                current = current.parentElement;
              }
              return false;
            }
            
            // Count all elements that are inside headers/footers (including the containers themselves)
            const elementsInHeaders = allVisibleElements.filter(el => isInsideHeaderOrFooter(el));
            
            // Debug: Check if our target paragraph is being filtered out
            const targetParagraph = allVisibleElements.find(el => 
              el.tagName.toLowerCase() === 'p' && el.textContent.includes('kontaktformulär')
            );
            
            if (targetParagraph) {
              log('🎯 TARGET PARAGRAPH FOUND in visible elements');
              log('   Text: "' + targetParagraph.textContent.substring(0, 50) + '..."');
              
              if (isCookieRelatedElement(targetParagraph)) {
                log('❌ TARGET PARAGRAPH filtered out by COOKIE filter');
              } else if (isInsideHeaderOrFooter(targetParagraph)) {
                log('❌ TARGET PARAGRAPH filtered out by HEADER/FOOTER filter (inside container)');
              } else {
                log('✅ TARGET PARAGRAPH should make it to processing');
              }
            } else {
              log('❌ TARGET PARAGRAPH not found in visible elements at all');
            }
            
            log('Debug: ' + allVisibleElements.length + ' visible elements total');
            log('Debug: Filtered out ' + cookieElements.length + ' cookie elements');
            log('Debug: Found ' + headerContainers.length + ' header containers');
            log('Debug: Found ' + footerContainers.length + ' footer containers');
            log('Debug: Filtered out ' + elementsInHeaders.length + ' elements inside headers/footers');
            
            const allElements = allVisibleElements
              .filter(el => !isCookieRelatedElement(el) && !isInsideHeaderOrFooter(el));
            
            const contentItems = [];
            const seenUrls = new Set();
            const seenTexts = new Map();
            const processedElements = new Set();
            
            for (const el of allElements) {
              // Skip if already processed
              if (processedElements.has(el)) {
                // Debug: Log when target elements are skipped
                if (el.tagName.toLowerCase() === 'p' && el.textContent.includes('kontaktformulär')) {
                  log('❌ SPECIFIC DEBUG: Target paragraph already processed');
                  log('   Text: "' + el.textContent.substring(0, 50) + '..."');
                }
                continue;
              }
              
              // Debug: Track our target paragraph
              if (el.tagName.toLowerCase() === 'p' && el.textContent.includes('kontaktformulär')) {
                log('🎯 SPECIFIC DEBUG: Found target paragraph');
                log('   Text: "' + el.textContent.substring(0, 50) + '..."');
                log('   Tag: ' + el.tagName);
                log('   Parent: ' + (el.parentElement ? el.parentElement.tagName : 'none') + '.' + (el.parentElement && el.parentElement.className ? el.parentElement.className : 'no-class'));
              }
              
              const contentData = extractContentData(el);
              if (contentData) {
                // Prevent duplicate images
                if (contentData.type === 'image') {
                  if (seenUrls.has(contentData.url)) continue;
                  seenUrls.add(contentData.url);
                }
                
                // Context-aware duplicate text detection
                if (contentData.type === 'text') {
                  log('📝 Processing text content: "' + contentData.text.substring(0, 30) + '..."');
                  const fullText = contentData.text.trim();
                  log('   Full text length: ' + fullText.length + ' chars');
                  
                  if (fullText.length <= 2) {
                    log('   ❌ Skipping very short text (' + fullText.length + ' chars)');
                    continue;
                  }
                  
                  // Context-aware duplicate detection
                  const textKey = fullText;
                  const elementInfo = {
                    tag: contentData.tag,
                    class: contentData.class || '',
                    parent: contentData.element.parentElement ? contentData.element.parentElement.tagName + '.' + (contentData.element.parentElement.className || '') : '',
                    position: contentData.element.getBoundingClientRect()
                  };
                  
                  // Check if this is a structural/UI element that should be allowed to repeat
                  const isStructuralElement = (
                    fullText.length < 50 && (
                      fullText.toLowerCase().includes('view') ||
                      fullText.toLowerCase().includes('read more') ||
                      fullText.toLowerCase().includes('learn more') ||
                      fullText.toLowerCase().includes('contact') ||
                      fullText.toLowerCase().includes('click') ||
                      fullText.toLowerCase().includes('see') ||
                      fullText.toLowerCase().includes('more') ||
                      fullText.toLowerCase().includes('button') ||
                      fullText.toLowerCase().includes('link')
                    )
                  );
                  
                  // Check if this is navigation/menu text that should be allowed to repeat  
                  const isNavigationElement = (
                    contentData.tag === 'a' || 
                    elementInfo.class.toLowerCase().includes('nav') ||
                    elementInfo.class.toLowerCase().includes('menu') ||
                    elementInfo.parent.toLowerCase().includes('nav') ||
                    elementInfo.parent.toLowerCase().includes('menu')
                  );
                  
                  if (seenTexts.has(textKey)) {
                    const existingItems = seenTexts.get(textKey);
                    
                    // Allow duplicates for structural/UI elements
                    if (isStructuralElement) {
                      log('   🔄 Allowing structural element repeat: "' + fullText + '"');
                      existingItems.push(elementInfo);
                      seenTexts.set(textKey, existingItems);
                    }
                    // Allow duplicates for navigation elements
                    else if (isNavigationElement) {
                      log('   🔄 Allowing navigation element repeat: "' + fullText + '"');
                      existingItems.push(elementInfo);
                      seenTexts.set(textKey, existingItems);
                    }
                    // Allow duplicates if they're in different visual positions (different cards/sections)
                    else {
                      const lastPosition = existingItems[existingItems.length - 1].position;
                      const currentPosition = elementInfo.position;
                      const distance = Math.sqrt(
                        Math.pow(currentPosition.left - lastPosition.left, 2) + 
                        Math.pow(currentPosition.top - lastPosition.top, 2)
                      );
                      
                      if (distance > 100) { // Different sections/cards
                        log('   🔄 Allowing positional duplicate (distance: ' + Math.round(distance) + 'px): "' + fullText.substring(0, 30) + '..."');
                        existingItems.push(elementInfo);
                        seenTexts.set(textKey, existingItems);
                      } else {
                        log('🔍 TRUE DUPLICATE DETECTED (same position):');
                        log('  Full text: "' + fullText + '"');
                        log('  Distance from previous: ' + Math.round(distance) + 'px');
                        log('  Element: ' + contentData.tag + '.' + (contentData.class || 'no-class'));
                        continue;
                      }
                    }
                  } else {
                    log('   ✅ Adding new text to seen list');
                    seenTexts.set(textKey, [elementInfo]);
                  }
                }
                
                // Prevent duplicate links
                if (contentData.type === 'link') {
                  const linkKey = contentData.url + ':' + contentData.text;
                  if (seenUrls.has(linkKey)) continue;
                  seenUrls.add(linkKey);
                }
                
                contentItems.push(contentData);
                processedElements.add(el);
                
                // Only mark direct content children as processed
                const directChildren = Array.from(el.children);
                for (const child of directChildren) {
                  if (isContentLeaf(child)) {
                    processedElements.add(child);
                  }
                }
              }
            }
            
            log('Found ' + contentItems.length + ' content items');
            
            // Phase 2: Adaptive Grouping
            log('Phase 2: Adaptive Grouping');
            const groups = adaptiveGrouping(contentItems);
            
            // Phase 3: Format results
            const results = groups.map(group => {
              if (group.items.length === 1) {
                // Single item - return as individual element
                const item = group.items[0];
                const result = {
                  type: item.type,
                  wrapper: false
                };
                
                if (item.type === 'image') {
                  result.url = item.url;
                  result.alt = item.alt || '';
                  result.source = item.source || 'img_tag';
                } else if (item.type === 'video') {
                  result.url = item.url;
                  result.poster = item.poster || '';
                } else if (item.type === 'link') {
                  result.url = item.url;
                  result.text = item.text;
                } else if (item.type === 'text') {
                  result.element = item.tag;
                  result.text = item.text;
                }
                
                if (item.class) result.class = item.class;
                if (item.id) result.id = item.id;
                
                return result;
              } else {
                // Multiple items - create group
                const children = group.items.map(item => {
                  const child = {
                    type: item.type,
                    wrapper: false
                  };
                  
                  if (item.type === 'image') {
                    child.url = item.url;
                    child.alt = item.alt || '';
                    child.source = item.source || 'img_tag';
                  } else if (item.type === 'video') {
                    child.url = item.url;
                    child.poster = item.poster || '';
                  } else if (item.type === 'link') {
                    child.url = item.url;
                    child.text = item.text;
                  } else if (item.type === 'text') {
                    child.element = item.tag;
                    child.text = item.text;
                  }
                  
                  if (item.class) child.class = item.class;
                  if (item.id) child.id = item.id;
                  
                  return child;
                });
                
                // Use container if available, otherwise use first item's parent
                const ancestor = group.container || group.items[0].element.parentElement;
                const result = {
                  type: 'group',
                  element: ancestor ? ancestor.tagName.toLowerCase() : 'div',
                  wrapper: true,
                  children: children,
                  pattern: group.pattern?.patternType || 'generic',
                  score: group.score || 0
                };
                
                if (ancestor && ancestor.className) result.class = ancestor.className;
                if (ancestor && ancestor.id) result.id = ancestor.id;
                
                return result;
              }
            });
            
            return {
              results: results,
              debug: debugLog
            };
          })()
        """)
        
        # Close the page
        await page.close()
        
        # Log debug information using Python logging
        if result.get('debug'):
            logger.info("=== SCRAPER DEBUG INFO ===")
            for debug_entry in result['debug']:
                if debug_entry.get('data'):
                    logger.info(f"{debug_entry['message']}: {debug_entry['data']}")
                else:
                    logger.info(debug_entry['message'])
            logger.info("=== END DEBUG INFO ===")
        
        return {
            "url": url,
            "data": result.get('results', []),
            "status": "success"
        }
        
    except Exception as e:
        logger.error(f"Scraping failed for {url}: {e}")
        if 'page' in locals():
            await page.close()
        raise HTTPException(status_code=500, detail=f"Scraping failed: {str(e)}")

from urllib.parse import urlparse

@app.get("/extract-urls")
async def extract_urls(url: str, same_domain: bool = True, unique: bool = True):
    """
    Extract all URLs from a webpage
    Parameters:
    - url: The URL to scrape
    - same_domain: If True, only returns URLs from the same domain (default: True)
    - unique: If True, removes duplicate URLs (default: True)
    """
    if not browser:
        raise HTTPException(status_code=500, detail="Browser not initialized")
    
    if not url:
        raise HTTPException(status_code=400, detail="URL parameter is required")
    
    try:
        # Parse the input URL to get domain info
        parsed_url = urlparse(url)
        base_domain = parsed_url.netloc.lower()
        scheme = parsed_url.scheme.lower()
        
        page = await browser.new_page()
        await page.goto(url, wait_until="networkidle")
        
        # Handle cookie consent dialogs
        try:
            await asyncio.sleep(3)
            cookie_selectors = [
                '[id*="cookie"] button:has-text("Accept")',
                '[id*="cookie"] button:has-text("Acceptera")',
                '[id*="cookie"] button:has-text("OK")',
                '[class*="cookie"] button:has-text("Accept")',
                '#CybotCookiebotDialogBodyButtonAccept',
                '[role="dialog"] button:has-text("Accept")',
                'button:has-text("Acceptera alla")',
                'button[data-cc-btn="accept"]'
            ]
            
            for selector in cookie_selectors:
                try:
                    if await page.locator(selector).first.is_visible(timeout=2000):
                        await page.locator(selector).first.click(timeout=2000)
                        await asyncio.sleep(2)
                        break
                except:
                    continue
        except Exception as e:
            logger.info(f"Cookie dialog handling: {e}")
        
        # Extract all href attributes from a elements
        urls = await page.evaluate("""() => {
            const links = Array.from(document.querySelectorAll('a[href]'));
            return links.map(link => {
                try {
                    return new URL(link.href, document.baseURI).href;
                } catch {
                    return null;
                }
            }).filter(url => url !== null);
        }""")
        
        # Extract src attributes from media elements
        media_urls = await page.evaluate("""() => {
            const elements = Array.from(document.querySelectorAll(`
                img[src], 
                video[src], 
                audio[src], 
                source[src], 
                iframe[src],
                link[rel="stylesheet"][href],
                script[src]
            `));
            
            return elements.map(el => {
                const url = el.src || el.href;
                try {
                    return new URL(url, document.baseURI).href;
                } catch {
                    return null;
                }
            }).filter(url => url !== null);
        }""")
        
        # Combine all URLs
        all_urls = urls + media_urls
        
        # Filter by same domain if requested
        if same_domain:
            filtered_urls = []
            for u in all_urls:
                try:
                    parsed_u = urlparse(u.lower())
                    # Check if same domain or subdomain
                    if parsed_u.netloc == base_domain or parsed_u.netloc.endswith(f".{base_domain}"):
                        filtered_urls.append(u)
                    # Also include relative URLs (without domain)
                    elif not parsed_u.netloc:
                        filtered_urls.append(f"{scheme}://{base_domain}{u}")
                except:
                    continue
            all_urls = filtered_urls
        
        # Remove duplicates if requested
        if unique:
            seen = set()
            all_urls = [u for u in all_urls if not (u in seen or seen.add(u))]
        
        await page.close()
        
        return {
            "url": url,
            "count": len(all_urls),
            "urls": all_urls,
            "status": "success"
        }
        
    except Exception as e:
        logger.error(f"URL extraction failed for {url}: {e}")
        if 'page' in locals():
            await page.close()
        raise HTTPException(status_code=500, detail=f"URL extraction failed: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)