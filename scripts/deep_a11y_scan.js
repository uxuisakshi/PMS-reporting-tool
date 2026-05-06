const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

// ── AI/ML Integration Helper Functions ───────────────────────────
function toNeedsReviewSeverity(impact) {
  const v = String(impact || "").toLowerCase().trim();
  if (v === "critical" || v === "blocker") return "Blocker";
  if (v === "serious" || v === "high") return "Critical";
  if (v === "moderate" || v === "medium" || v === "major") return "Major";
  if (v === "minor" || v === "low") return "Minor";
  return "Major";
}

function toLabelText(id, fallback) {
  if (!id) return fallback || "Field";
  const label = id
    .replace(/[_-]/g, " ")
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .trim();
  return label.charAt(0).toUpperCase() + label.slice(1);
}

async function isOllamaAvailable() {
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 2000);
    const response = await fetch('http://localhost:11434/api/tags', { signal: controller.signal });
    clearTimeout(timeoutId);
    return response.ok;
  } catch (e) {
    return false;
  }
}

async function getAIEnhancedFindings(violation, snippet, feedbackPath, token = null) {
  if (!(await isOllamaAvailable())) {
    return null;
  }
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 minutes for slow Llama3 inference on VPS
  
  try {
    const ruleId = violation.id;
    const impact = violation.impact;
    const description = violation.description;
    const help = violation.help;
    
    // Load comprehensive learning feedback for few-shot prompting across all fields
    let feedbackPrompt = "";
    try {
        if (fs.existsSync(feedbackPath)) {
            const feedback = JSON.parse(fs.readFileSync(feedbackPath, 'utf8'));
            const activeFeedback = feedback.filter(f => f.rule_id === ruleId).slice(0, 3);
            if (activeFeedback.length > 0) {
                feedbackPrompt = "\nUse these past corrections as style examples for all fields:\n" + 
                activeFeedback.map(f => {
                    return `Example Input Snippet: ${f.snippet}\n` +
                           `Example Output Actual Results: ${f.actual_results || ''}\n` +
                           `Example Output Incorrect Code: ${f.incorrect_code || ''}\n` +
                           `Example Output Recommendation: ${f.improved_recommendation || f.improved || ''}\n` +
                           `Example Output Correct Code: ${f.correct_code || ''}`;
                }).join("\n---\n");
            }
        }
    } catch (e) { /* silent fail on feedback load */ }

    const prompt = `You are a professional WCAG 2.2 Accessibility expert. 
Analyze this accessibility violation and provide a structured JSON response.

Violation: ${ruleId} (${impact})
Description: ${description}
Help text: ${help}
Target HTML Snippet: ${snippet}
${feedbackPrompt}

Instructions:
1. Provide a technical, professional, and audit-ready reporting style.
2. Output your findings strictly as a JSON object with these keys:
   - "actual_results": A detailed explanation of why the element fails accessibility. Include the specific WCAG Success Criterion (e.g., SC 1.1.1) and the practical impact on users with disabilities (e.g., "Screen reader users cannot identify the purpose of this control").
   - "incorrect_code": The specific failing part of the HTML snippet that MUST be changed (exact snippet).
   - "recommendation": A detailed, step-by-step recommendation for a developer to fix the issue. Use a numbered list (1., 2., 3.) for the steps if multiple actions are required.
   - "correct_code": Providing the exact corrected code snippet for the provided target snippet. This should include:
     a) The corrected HTML.
     b) Any necessary CSS within <style> tags if it's required for the fix (e.g., focus indicators, hiding content).
     c) Any necessary Javascript within <script> tags if the fix requires logic (e.g., ARIA state management, keyboard event handling, focus management).
3. Be concise but thorough in the steps. Maintain a consistent expert tone.
4. Output ONLY the JSON. No preamble or markdown blocks.`;

    const response = await fetch('http://localhost:11434/api/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: 'llama3:latest', // Corrected to user's installed model
        prompt: prompt,
        stream: false,
        format: 'json'
      }),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    if (response.ok) {
      const result = await response.json();
      const aiResponse = result.response.trim();
      try {
          // Robust JSON extraction
          const startIdx = aiResponse.indexOf('{');
          const endIdx = aiResponse.lastIndexOf('}');
          if (startIdx !== -1 && endIdx !== -1 && endIdx > startIdx) {
              return JSON.parse(aiResponse.slice(startIdx, endIdx + 1));
          }
          return JSON.parse(aiResponse);
      } catch (pe) {
          // Fallback if model didn't return valid JSON
          return null;
      }
    }
  } catch (err) {
    clearTimeout(timeoutId);
    return null; 
  }
  return null;
}

async function runAIDiscoveryAudit(page, feedbackPath) {
  if (!(await isOllamaAvailable())) {
      throw new Error('AI Discovery requires Ollama to be running on localhost:11434. Please start Ollama or use standard scan.');
  }
  const simplifiedDOM = await page.evaluate(() => {
    function getCleanAttributes(el) {
      const attrs = {};
      const important = ['id', 'role', 'aria-label', 'aria-labelledby', 'aria-describedby', 'alt', 'title', 'type', 'name', 'placeholder'];
      for (const attr of el.attributes) {
        if (important.includes(attr.name.toLowerCase()) || attr.name.toLowerCase().startsWith('aria-')) {
          attrs[attr.name] = attr.value;
        }
      }
      return attrs;
    }

    function simplify(node, depth = 0) {
      if (depth > 12) return null; // Cap depth to prevent context blowup
      if (node.nodeType === Node.TEXT_NODE) {
        const text = node.textContent.trim();
        return text ? { type: 'text', val: text.slice(0, 50) } : null; // Truncate text
      }
      if (node.nodeType !== Node.ELEMENT_NODE) return null;

      const tag = node.tagName.toLowerCase();
      // Expanded exclusion list
      if (['script', 'style', 'noscript', 'iframe', 'svg', 'path', 'meta', 'link'].includes(tag)) return null;

      const attrs = getCleanAttributes(node);
      const isMeaningful = (Object.keys(attrs).length > 0) || 
                          (['button', 'input', 'select', 'textarea', 'a', 'form', 'h1', 'h2', 'h3', 'nav', 'main', 'footer', 'header'].includes(tag));

      const children = [];
      for (const child of node.childNodes) {
        const s = simplify(child, depth + 1);
        if (s) children.push(s);
      }

      // If it's a generic div/span with no attributes and no meaningful children, prune it
      if (['div', 'span'].includes(tag) && !isMeaningful && children.length === 0) return null;
      
      // If it's a generic div/span with no attributes, but has 1 child, flatten it
      if (['div', 'span'].includes(tag) && !isMeaningful && children.length === 1) return children[0];

      return { tag, attrs, children };
    }
    return simplify(document.body);
  });

  const prompt = `Analyze this simplified DOM and identify accessibility issues (WCAG 2.2). 
Output ONLY a JSON array of up to 10 issues with these keys: 
"rule_id", "title", "severity", "actual_results", "incorrect_code", "recommendation", "correct_code", "wcag_sc".

DOM:
${JSON.stringify(simplifiedDOM).slice(0, 4000)}`;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutes timeout
  console.log('Analyzing page with AI (Ultra-light mode for low memory)...');

  try {
    const response = await fetch('http://localhost:11434/api/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: 'llama3:latest',
        prompt: prompt,
        stream: false,
        format: 'json'
      }),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    if (response.ok) {
      const result = await response.json();
      const aiResponse = result.response.trim();
      const startIdx = aiResponse.indexOf('[');
      const endIdx = aiResponse.lastIndexOf(']');
      if (startIdx !== -1 && endIdx !== -1) {
        const jsonStr = aiResponse.slice(startIdx, endIdx + 1);
        const rawFindings = JSON.parse(jsonStr);
        if (!Array.isArray(rawFindings)) {
             throw new Error('AI response is not a JSON array');
        }
        return (rawFindings || []).map(f => {
          const sev = String(f.severity || 'moderate').toLowerCase();
          return {
            ...f,
            rule_id: String(f.rule_id || 'ai-discovery-' + Math.random().toString(36).slice(2, 7)).trim(),
            title: String(f.title || 'AI Discovery finding').trim(),
            severity: sev,
            needs_review_severity: toNeedsReviewSeverity(sev),
            wcag_sc: String(f.wcag_sc || '').trim(),
            wcag_name: String(f.wcag_name || '').trim(),
            wcag_level: String(f.wcag_level || '').trim(),
            actual_results: String(f.actual_results || '').trim(),
            incorrect_code: String(f.incorrect_code || '').trim(),
            recommendation: String(f.recommendation || '').trim(),
            correct_code: String(f.correct_code || '').trim(),
            screenshots: [],
            occurrence_count: 1,
            discovery_type: 'ai_discovery'
          };
        });
      } else {
        throw new Error('AI response did not contain a valid JSON array of findings');
      }
    } else {
      const errTxt = await response.text();
      throw new Error(`Ollama API error: ${response.status} ${errTxt}`);
    }
  } catch (err) {
    clearTimeout(timeoutId);
    console.error('AI Discovery Audit failed:', err.message);
    throw err; // Re-throw to signal scan failure
  }
}
// ──────────────────────────────────────────────────────────────────

function loadLocalEngineSource() {
  const localEnginePath = path.join(__dirname, 'vendor', 'axe-core', 'axe.min.js');
  if (!fs.existsSync(localEnginePath)) {
    throw new Error(`Local accessibility engine not found at ${localEnginePath}`);
  }
  return fs.readFileSync(localEnginePath, 'utf8');
}
const axeSource = loadLocalEngineSource();

function parseArgs(argv) {
  const out = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const key = a.slice(2);
    const val = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : '1';
    out[key] = val;
  }
  return out;
}

function reportStatus(token, payload) {
  if (!token) return;
  try {
    const tmpDir = path.join(__dirname, '..', 'tmp');
    if (!fs.existsSync(tmpDir)) fs.mkdirSync(tmpDir, { recursive: true });
    const progressPath = path.join(tmpDir, `a11y_progress_${token}.json`);
    
    let existing = {};
    if (fs.existsSync(progressPath)) {
      existing = JSON.parse(fs.readFileSync(progressPath, 'utf8'));
    }
    
    const updated = { ...existing, ...payload, updated_at: new Date().toISOString() };
    fs.writeFileSync(progressPath, JSON.stringify(updated, null, 2));
  } catch (e) {
    console.error('Failed to report status:', e.message);
  }
}

function findBrowserExecutable() {
  const candidates = [
    process.env.CHROME_PATH,
    process.env.PUPPETEER_EXECUTABLE_PATH,
    // Windows Common Paths (Primary on Windows)
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    // Linux Common Paths
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium'
  ].filter(Boolean);

  for (const c of candidates) {
    try {
      if (fs.existsSync(c)) return c;
    } catch (_) {}
  }
  return null;
}

function sanitizeName(v) {
  return String(v || '')
    .toLowerCase()
    .replace(/[^a-z0-9-_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80) || 'finding';
}

function uniq(arr) {
  return Array.from(new Set((arr || []).filter(Boolean)));
}

function neutralizeToolBranding(text) {
  let s = String(text || "");
  s = s.replace(/\baxe-core\b/gi, "automated accessibility checks");
  s = s.replace(/\baxe\b/gi, "accessibility checks");
  s = s.replace(/\bdeque\b/gi, "accessibility guidance");
  return s;
}

function normalizeSectionLabel(sectionRaw) {
  const s = String(sectionRaw || "").trim();
  if (!s) return "page section";
  const lower = s.toLowerCase();
  if (lower === "header") return "Header section";
  if (lower === "nav") return "Navigation section";
  if (lower === "main") return "Main section";
  if (lower === "footer") return "Footer section";
  if (lower === "aside") return "Sidebar section";
  if (lower === "section") return "Page section";
  if (lower === "article") return "Article section";
  if (lower === "form") return "Form section";
  if (lower === "page section") return "Page section";
  if (lower === "footer section") return "Footer section";
  if (lower === "header section") return "Header section";
  return s;
}

function formatSectionForOutput(sectionRaw) {
  const section = normalizeSectionLabel(sectionRaw);
  const lower = section.toLowerCase();
  const unquoted = new Set([
    "header section",
    "navigation section",
    "main section",
    "footer section",
    "sidebar section",
    "page section",
    "article section",
    "form section"
  ]);
  if (unquoted.has(lower)) return section;
  return `"${section}"`;
}

function normalizeFailureSummary(text) {
  let s = String(text || "").replace(/\s+/g, " ").trim();
  if (!s) return "";
  s = s.replace(/^Fix (any|all) of the following:\s*/i, "");
  s = s.replace(/^Fix all of these:\s*/i, "");
  s = s.replace(/^Fix any of these:\s*/i, "");
  return s.trim();
}

function simplifyFailureSummary(summary, ruleId) {
  const s = normalizeFailureSummary(summary);
  const r = String(ruleId || "").toLowerCase().trim();
  if (!s) return "";

  function normalizeElementPhrase(text) {
    let out = String(text || "");
    const map = [
      { re: /\bselect element\b/gi, tag: "select" },
      { re: /\binput element\b/gi, tag: "input" },
      { re: /\bbutton element\b/gi, tag: "button" },
      { re: /\blink element\b/gi, tag: "a" },
      { re: /\bimage element\b/gi, tag: "img" },
      { re: /\bform element\b/gi, tag: "form" },
      { re: /\btextarea element\b/gi, tag: "textarea" }
    ];
    map.forEach((item) => {
      out = out.replace(item.re, `The <${item.tag}> element`);
    });
    return out;
  }

  if (r === "select-name") {
    return "The <select> element is missing an accessible name (label, aria-label, aria-labelledby, or title).";
  }
  if (r === "label") {
    return "The form control element is missing an accessible label (visible label or ARIA label association).";
  }
  if (r === "button-name") {
    return "The <button> element is missing an accessible name (visible text, aria-label, aria-labelledby, or title).";
  }
  if (r === "image-alt") {
    return "The <img> element has no accessible text alternative. Provide one method: <code>alt</code>, <code>aria-label</code>, <code>aria-labelledby</code>, <code>title</code>, or mark decorative with <code>role=\"presentation\"</code>/<code>role=\"none\"</code>.";
  }
  if (r === "input-image-alt") {
    return "The <input type=\"image\"> element has no accessible text alternative. Add descriptive <code>alt</code> text.";
  }

  // Convert dense automated checks into a clear, human-readable sentence.
  if (
    /aria-label attribute does not exist or is empty/i.test(s) ||
    /aria-labelledby attribute does not exist/i.test(s) ||
    /has no title attribute/i.test(s) ||
    /does not have an alt attribute/i.test(s)
  ) {
    const isImg = /\bimg\b|image/i.test(s) || r === "image-alt" || r === "input-image-alt";
    if (isImg) {
      return "Accessible name is missing. Use <code>alt</code> (preferred for images), or <code>aria-label</code>, <code>aria-labelledby</code>, or <code>title</code>. For decorative images, use <code>alt=\"\"</code>.";
    }
    return "Accessible name is missing. Add a visible label/text, or provide <code>aria-label</code>, <code>aria-labelledby</code>, or <code>title</code> as appropriate.";
  }
  return normalizeElementPhrase(s);
}

function normalizeIssueDescription(description, recommendation, ruleId) {
  let d = String(description || "").replace(/\s+/g, " ").trim();
  if (!d) return "";
  const rec = String(recommendation || "").replace(/\s+/g, " ").trim();
  if (rec && d.toLowerCase() === rec.toLowerCase()) {
    return "";
  }
  if (rec && d.toLowerCase().includes(rec.toLowerCase())) {
    d = d.replace(new RegExp(rec.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "ig"), "").trim();
  }
  if (!d) return "";
  return d;
}

function formatActualResults(url, description, groupedFailures, recommendation, ruleId) {
  const lines = [];
  lines.push(`URL: ${url}`);
    const groups = Array.isArray(groupedFailures) ? groupedFailures : [];
  if (!groups.length) {
    lines.push('');
    lines.push('- "No instance details available" in "page section"');
    return lines.join("\n");
  }

  for (const g of groups) {
    const summary = simplifyFailureSummary(g && g.summary ? g.summary : "", ruleId);
    const instances = Array.isArray(g && g.instances) ? g.instances : [];
    lines.push('');
    if (summary) lines.push(summary);
    if (instances.length) {
      for (const item of instances) {
        const name = item.instance_name || "Unnamed element";
        const section = formatSectionForOutput(item.section_context || "page section");
        lines.push(`- "${name}" in ${section}`);
      }
    } else {
      lines.push('- "No instance details available" in Page section');
    }
    // Leave one blank line after each issue summary block for readability.
    lines.push('');
  }
  return lines.join("\n");
}

// Severity mapping is now defined at the top of the file

function extractWcagMeta(violation) {
  const tags = Array.isArray(violation.tags) ? violation.tags.map((t) => String(t || "").toLowerCase()) : [];
  const scTags = uniq(tags.filter((t) => /^wcag\d{3,4}$/.test(t)));
  const scList = scTags.map((t) => {
    const d = t.replace("wcag", "");
    if (d.length === 3) return `${d[0]}.${d[1]}.${d[2]}`;
    if (d.length === 4) return `${d[0]}.${d[1]}.${d[2]}${d[3]}`;
    return t;
  });
  let level = "";
  if (tags.includes("wcag2aaa") || tags.includes("wcag21aaa")) level = "AAA";
  else if (tags.includes("wcag2aa") || tags.includes("wcag21aa")) level = "AA";
  else if (tags.includes("wcag2a") || tags.includes("wcag21a")) level = "A";

  const nameMap = {
    "color-contrast": "Contrast (Minimum)",
    "link-name": "Link Purpose (In Context)",
    "button-name": "Name, Role, Value",
    "image-alt": "Non-text Content",
    "label": "Labels or Instructions",
    "document-title": "Page Titled",
    "html-has-lang": "Language of Page",
    "html-lang-valid": "Language of Page",
    "heading-order": "Headings and Labels",
    "landmark-one-main": "Bypass Blocks",
  };
  const wcagName = nameMap[String(violation.id || "").toLowerCase()] || neutralizeToolBranding(String(violation.help || "")).trim() || "WCAG criterion";
  return { scList, wcagName, level };
}

function getRecommendation(violation) {
  const id = String(violation.id || "").toLowerCase();
  if (id.includes("color-contrast") || id.includes("contrast")) {
    return "Ensure the contrast between foreground and background colors meets WCAG 2 AA minimum contrast ratio thresholds";
  }
  const help = neutralizeToolBranding(String(violation.help || "")).trim();
  if (help) return help;
  return "Fix this accessibility issue according to WCAG 2 AA requirements.";
}

function extractControlHintsFromSnippets(snippets, tagName) {
  const hints = [];
  const seen = new Set();
  (snippets || []).forEach((raw) => {
    const html = String(raw || "").trim();
    if (!html) return;
    const tag = String(tagName || "").toLowerCase();
    if (tag && !new RegExp(`<\\s*${tag}\\b`, "i").test(html)) return;
    const idMatch = html.match(/\bid\s*=\s*["']([^"']+)["']/i);
    const nameMatch = html.match(/\bname\s*=\s*["']([^"']+)["']/i);
    const clsMatch = html.match(/\bclass\s*=\s*["']([^"']+)["']/i);
    const idVal = idMatch ? String(idMatch[1]).trim() : "";
    const nameVal = nameMatch ? String(nameMatch[1]).trim() : "";
    const clsVal = clsMatch ? String(clsMatch[1]).trim() : "";
    const key = `${idVal.toLowerCase()}||${nameVal.toLowerCase()}||${tag}`;
    if (seen.has(key)) return;
    seen.add(key);
    hints.push({ id: idVal, name: nameVal, cls: clsVal, tag: tag || "control" });
  });
  return hints;
}

function extractTagHintsFromSnippets(snippets, tagNames) {
  const allowed = new Set((tagNames || []).map((t) => String(t || "").toLowerCase().trim()).filter(Boolean));
  const out = [];
  const seen = new Set();
  (snippets || []).forEach((raw) => {
    const html = String(raw || "");
    if (!html) return;
    const m = html.match(/<\s*([a-z0-9-]+)\b([^>]*)>/i);
    if (!m) return;
    const tag = String(m[1] || "").toLowerCase().trim();
    if (!tag || (allowed.size > 0 && !allowed.has(tag))) return;
    const attrs = {};
    const attrPart = String(m[2] || "");
    let am;
    const rx = /([a-zA-Z_:.-]+)\s*=\s*["']([^"']*)["']/g;
    while ((am = rx.exec(attrPart)) !== null) {
      attrs[String(am[1] || "").toLowerCase()] = String(am[2] || "").trim();
    }
    const key = `${tag}||${attrs.id || ""}||${attrs.name || ""}||${attrs.href || ""}||${attrs.src || ""}`;
    if (seen.has(key)) return;
    seen.add(key);
    out.push({ tag, attrs });
  });
  return out;
}

function toLabelText(idOrName, fallback) {
  const raw = String(idOrName || fallback || "Field").trim();
  if (!raw) return "Field";
  return raw.replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function detectFieldLabelFromAttrs(attrs, fallback) {
  const base = attrs.id || attrs.name || attrs['aria-label'] || fallback || "Field";
  return toLabelText(base, fallback || "Field");
}

function stripAttribute(openingTag, attrName) {
  const n = String(attrName || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return String(openingTag || "").replace(new RegExp(`\\s+${n}\\s*=\\s*["'][^"']*["']`, "ig"), "");
}

function upsertAttribute(openingTag, attrName, attrValue) {
  let tag = String(openingTag || "");
  const n = String(attrName || "");
  if (!tag) return tag;
  if (new RegExp(`\\s+${n}\\s*=\\s*["'][^"']*["']`, "i").test(tag)) {
    return tag.replace(new RegExp(`(${n}\\s*=\\s*["'])[^"']*(["'])`, "i"), `$1${String(attrValue)}$2`);
  }
  return tag.replace(/>$/, ` ${n}="${String(attrValue)}">`);
}

function extractOpeningTag(html) {
  const m = String(html || "").match(/<\s*([a-z0-9-]+)\b[^>]*>/i);
  return m ? m[0] : "";
}

function extractClosingTag(html) {
  const m = String(html || "").match(/<\/\s*([a-z0-9-]+)\s*>/i);
  return m ? m[0] : "";
}

function inferControlTextFromSnippet(raw, fallback) {
  const html = String(raw || "");
  const alt = (html.match(/\balt\s*=\s*["']([^"']+)["']/i) || [])[1] || "";
  const title = (html.match(/\btitle\s*=\s*["']([^"']+)["']/i) || [])[1] || "";
  const text = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
  let out = alt || title || text || fallback || "Action";
  if (!out || out.toLowerCase() === "button") {
    if (/prev|left/i.test(html)) out = "Previous";
    else if (/next|right/i.test(html)) out = "Next";
  }
  return out.trim() || "Action";
}

function inferDirectionalLabel(raw, fallback) {
  const html = String(raw || "");
  const existingAria = (html.match(/\baria-label\s*=\s*["']([^"']+)["']/i) || [])[1] || "";
  if (existingAria.trim()) return existingAria.trim();
  const existingTitle = (html.match(/\btitle\s*=\s*["']([^"']+)["']/i) || [])[1] || "";
  if (existingTitle.trim()) return existingTitle.trim();
  const existingAlt = (html.match(/\balt\s*=\s*["']([^"']+)["']/i) || [])[1] || "";
  if (existingAlt.trim()) return existingAlt.trim();
  if (/next|right/i.test(html)) return "Next";
  if (/prev|previous|left/i.test(html)) return "Previous";
  if (/close|dismiss/i.test(html)) return "Close";
  return String(fallback || "Action").trim() || "Action";
}

function extractPlainText(html, fallback) {
  const txt = String(html || "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
  return txt || String(fallback || "").trim();
}

function buildIssueSpecificCorrectCode(ruleId, snippets, failureSummaries) {
  const id = String(ruleId || "").toLowerCase().trim();
  const codeSnippets = (snippets || []).map((s) => String(s || "").trim()).filter(Boolean).slice(0, 8);
  if (!codeSnippets.length) return "";

  const tagHints = extractTagHintsFromSnippets(codeSnippets, ["input", "select", "textarea", "button", "a", "img"]);
  const out = [];

  if (id === "select-name") {
    tagHints.filter((x) => x.tag === "select").forEach((item) => {
      const attrs = item.attrs || {};
      const idAttr = attrs.id || attrs.name || "select_field";
      const labelText = detectFieldLabelFromAttrs(attrs, "Select option");
      const clsAttr = attrs.class ? ` class="${attrs.class}"` : "";
      const nameAttr = attrs.name || idAttr;
      out.push(`<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}"${clsAttr}>
  <option value="">Select ${labelText}</option>
</select>`);
    });
    return out.join("\n\n");
  }

  if (id === "label" || id === "aria-input-field-name") {
    tagHints.filter((x) => ["input", "select", "textarea"].includes(x.tag)).forEach((item) => {
      const attrs = item.attrs || {};
      const tag = item.tag;
      const idAttr = attrs.id || attrs.name || `${tag}_field`;
      const nameAttr = attrs.name || idAttr;
      const labelText = detectFieldLabelFromAttrs(attrs, tag);
      const clsAttr = attrs.class ? ` class="${attrs.class}"` : "";
      if (tag === "select") {
        out.push(`<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}"${clsAttr}>
  <option value="">Select ${labelText}</option>
</select>`);
      } else if (tag === "textarea") {
        out.push(`<label for="${idAttr}">${labelText}</label>
<textarea id="${idAttr}" name="${nameAttr}"${clsAttr}></textarea>`);
      } else {
        const typeAttr = attrs.type ? ` type="${attrs.type}"` : ` type="text"`;
        out.push(`<label for="${idAttr}">${labelText}</label>
<input id="${idAttr}" name="${nameAttr}"${typeAttr}${clsAttr}>`);
      }
    });
    return out.join("\n\n");
  }

  if (id === "button-name") {
    codeSnippets.forEach((raw) => {
      if (/<\s*button\b/i.test(raw)) {
        const open = extractOpeningTag(raw);
        const close = extractClosingTag(raw) || "</button>";
        const txt = raw.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
        const fallbackText = txt || "Submit";
        out.push(`${open}${fallbackText}${close}`);
      } else if (/<\s*input\b/i.test(raw)) {
        let open = extractOpeningTag(raw);
        open = upsertAttribute(open, "type", /type\s*=\s*["'](submit|reset|button)["']/i.test(open) ? RegExp.$1 : "button");
        open = upsertAttribute(open, "value", "Submit");
        out.push(open);
      }
    });
    return out.join("\n\n");
  }

  if (id === "link-name") {
    codeSnippets.forEach((raw) => {
      if (!/<\s*a\b/i.test(raw)) return;
      const open = extractOpeningTag(raw) || `<a href="#">`;
      const hrefMatch = open.match(/\bhref\s*=\s*["']([^"']+)["']/i);
      const href = hrefMatch ? hrefMatch[1] : "#";
      out.push(`<a href="${href}">Descriptive link text</a>`);
    });
    return out.join("\n\n");
  }

  if (id === "image-alt" || id === "image-redundant-alt") {
    codeSnippets.forEach((raw) => {
      if (!/<\s*img\b/i.test(raw)) return;
      let open = extractOpeningTag(raw);
      open = upsertAttribute(open, "alt", id === "image-redundant-alt" ? "" : "Meaningful description");
      out.push(open);
    });
    return out.join("\n\n");
  }

  if (id === "presentation-role-conflict" || id === "aria-allowed-role") {
    codeSnippets.forEach((raw) => {
      let open = extractOpeningTag(raw);
      open = stripAttribute(open, "role");
      if (!open) return;
      if (/<\s*button\b/i.test(open)) {
        const label = inferDirectionalLabel(raw, inferControlTextFromSnippet(raw, "Action"));
        const imgMatch = String(raw).match(/<\s*img\b[^>]*>/i);
        if (imgMatch && imgMatch[0]) {
          let imgOpen = String(imgMatch[0]);
          // Native-first naming: keep one source of accessible name (button text), make icon decorative.
          imgOpen = upsertAttribute(imgOpen, "alt", "");
          out.push(`${open}${imgOpen}<span class="visually-hidden">${label}</span>${extractClosingTag(raw) || "</button>"}`);
        } else {
          out.push(`${open}${label}${extractClosingTag(raw) || "</button>"}`);
        }
      } else {
        out.push(open + (extractClosingTag(raw) || ""));
      }
    });
    return out.join("\n\n");
  }

  if (id === "autocomplete-valid") {
    tagHints.filter((x) => x.tag === "input").forEach((item) => {
      const attrs = item.attrs || {};
      const idAttr = attrs.id || attrs.name || "field";
      const nameAttr = attrs.name || idAttr;
      const type = (attrs.type || "").toLowerCase();
      const token = /email/.test(nameAttr) || type === "email" ? "email"
        : /phone|mobile|tel/.test(nameAttr) ? "tel"
        : /name/.test(nameAttr) ? "name"
        : "on";
      out.push(`<label for="${idAttr}">${detectFieldLabelFromAttrs(attrs, "Field")}</label>
<input id="${idAttr}" name="${nameAttr}" type="${type || "text"}" autocomplete="${token}">`);
    });
    return out.join("\n\n");
  }

  if (id === "nested-interactive") {
    codeSnippets.forEach((raw) => {
      if (/<\s*a\b[\s\S]*<\s*button\b/i.test(raw) || /<\s*button\b[\s\S]*<\s*a\b/i.test(raw)) {
        const hrefMatch = raw.match(/\bhref\s*=\s*["']([^"']+)["']/i);
        const href = hrefMatch ? hrefMatch[1] : "#";
        const text = raw.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim() || "Open";
        out.push(`<a href="${href}" class="btn">${text}</a>`);
      }
    });
    return out.join("\n\n");
  }

  if (id === "color-contrast") {
    const contrast = (failureSummaries || []).find((s) => /contrast/i.test(String(s || ""))) || "";
    out.push(`/* Adjust colors so text contrast meets WCAG AA */\n.text-on-bg {\n  color: #1a1a1a;\n  background-color: #ffffff;\n}`);
    if (contrast) out.push(`/* Reference failing detail: ${normalizeFailureSummary(contrast)} */`);
    return out.join("\n\n");
  }

  if (id === "heading-order" || id.includes("heading")) {
    // Build corrected heading examples from the exact failing heading snippets.
    codeSnippets.forEach((raw) => {
      const m = String(raw).match(/<\s*h([1-6])\b([^>]*)>([\s\S]*?)<\/\s*h[1-6]\s*>/i);
      if (!m) return;
      const currentLevel = Math.max(1, Math.min(6, parseInt(String(m[1] || "1"), 10) || 1));
      const attrsPart = String(m[2] || "");
      const headingText = extractPlainText(m[3] || "", "Section heading");
      // If an <h4> caused skip, suggest the nearest non-skipping level (<h3>).
      const suggestedLevel = currentLevel > 1 ? currentLevel - 1 : 1;
      out.push(`<h${suggestedLevel}${attrsPart}>${headingText}</h${suggestedLevel}>`);
    });
    if (out.length) return out.join("\n\n");
    return `<h1>Page title</h1>
<h2>Section heading</h2>
<h3>Sub section heading</h3>`;
  }

  return "";
}

function buildDetailedRecommendation(baseLine, steps) {
  const head = String(baseLine || "").trim();
  const items = uniq((steps || []).map((s) => String(s || "").trim()).filter(Boolean));
  if (!items.length) return head || "Apply accessibility fixes for this rule.";
  if (!head) {
    return items.map((s) => `- ${s}`).join("\n");
  }
  return `${head}\n${items.map((s) => `- ${s}`).join("\n")}`;
}

function extractRoleHintsFromSnippets(snippets) {
  const roles = new Set();
  const tags = new Set();
  (snippets || []).forEach((raw) => {
    const html = String(raw || "");
    if (!html) return;
    const roleMatches = html.match(/\brole\s*=\s*["']([^"']+)["']/gi) || [];
    roleMatches.forEach((m) => {
      const mm = m.match(/["']([^"']+)["']/);
      if (mm && mm[1]) roles.add(String(mm[1]).toLowerCase().trim());
    });
    const tagMatch = html.match(/<\s*([a-z0-9-]+)/i);
    if (tagMatch && tagMatch[1]) tags.add(String(tagMatch[1]).toLowerCase().trim());
  });
  return { roles: Array.from(roles), tags: Array.from(tags) };
}

function getApgReferences(ruleId, snippets) {
  const id = String(ruleId || "").toLowerCase().trim();
  const { roles, tags } = extractRoleHintsFromSnippets(snippets || []);
  const links = new Set();
  const add = (u) => { if (u) links.add(u); };
  const base = "https://www.w3.org/WAI/ARIA/apg/patterns";

  if (id.includes("button")) add(`${base}/button/`);
  if (id.includes("link")) add(`${base}/link/`);
  if (id.includes("select") || roles.includes("listbox")) add(`${base}/listbox/`);
  if (roles.includes("combobox")) add(`${base}/combobox/`);
  if (roles.includes("menu") || roles.includes("menubar")) add(`${base}/menubar/`);
  if (roles.includes("dialog") || roles.includes("alertdialog")) add(`${base}/dialog-modal/`);
  if (roles.includes("tablist") || roles.includes("tab")) add(`${base}/tabs/`);
  if (roles.includes("radiogroup") || roles.includes("radio")) add(`${base}/radio/`);
  if (roles.includes("grid") || roles.includes("row")) add(`${base}/grid/`);
  if (roles.includes("tree") || roles.includes("treeitem")) add(`${base}/treeview/`);
  if (roles.includes("accordion") || id.includes("accordion")) add(`${base}/accordion/`);
  if (id === "aria-required-children" || id === "aria-allowed-role" || id === "presentation-role-conflict") {
    add("https://www.w3.org/WAI/ARIA/apg/");
  }
  if (tags.includes("button")) add(`${base}/button/`);

  return Array.from(links);
}

function appendReferenceLinks(recommendation, links) {
  const rec = String(recommendation || "").trim();
  const refs = (links || []).map((u) => String(u || "").trim()).filter(Boolean);
  if (!refs.length) return rec;
  return `${rec}\n- Reference: ${refs.join(" | ")}`;
}

function buildGenericCorrectCode(ruleId) {
  const id = String(ruleId || "").toLowerCase().trim();
  if (id.includes("color-contrast") || id.includes("contrast")) {
    return `<style>
.text-on-brand {
  color: #ffffff;
  background-color: #005fcc; /* keep text contrast >= 4.5:1 */
}
</style>`;
  }
  if (id.includes("autocomplete")) {
    return `<label for="email">Email</label>
<input id="email" name="email" type="email" autocomplete="email">`;
  }
  if (id.includes("button")) {
    return `<button type="button">Submit</button>`;
  }
  if (id.includes("link")) {
    return `<a href="/details">View details</a>`;
  }
  if (id.includes("image")) {
    return `<img src="/images/product.png" alt="Product image">`;
  }
  if (id.includes("select")) {
    return `<label for="department">Department</label>
<select id="department" name="department">
  <option value="">Select Department</option>
</select>`;
  }
  if (id.includes("label") || id.includes("input-field-name")) {
    return `<label for="full_name">Full name</label>
<input id="full_name" name="full_name" type="text">`;
  }
  if (id.includes("region") || id.includes("landmark")) {
    return `<header>...</header>
<main id="main-content">...</main>
<footer>...</footer>`;
  }
  if (id.includes("heading")) {
    return `<h1>Page title</h1>
<h2>Section title</h2>
<h3>Sub section</h3>`;
  }
  if (id.includes("lang")) {
    return `<html lang="en">`;
  }
  if (id.includes("nested-interactive")) {
    return `<a class="btn btn-primary" href="/apply">Apply Now</a>`;
  }
  if (id.includes("presentation-role-conflict")) {
    return `<a href="/docs">Download document</a>`;
  }
  if (id.includes("aria-hidden-focus")) {
    return `<div aria-hidden="true">
  <button tabindex="-1" aria-hidden="true">Hidden action</button>
</div>`;
  }
  if (id.includes("target-size")) {
    return `<button class="touch-target">Apply</button>
<style>
.touch-target { min-width: 24px; min-height: 24px; padding: 8px 12px; }
</style>`;
  }
  return `<!-- Update markup for rule: ${id || "accessibility-rule"} -->
<div>Accessible, semantic markup</div>`;
}

function getRuleSpecificGuidance(violation, context) {
  const id = String(violation && violation.id || "").toLowerCase().trim();
  const defaultRec = getRecommendation(violation);
  const snippets = (context && Array.isArray(context.snippets)) ? context.snippets : [];
  const failureSummaries = uniq(((context && Array.isArray(context.failureSummaries)) ? context.failureSummaries : [])
    .map((s) => normalizeFailureSummary(s))
    .filter(Boolean));
  const aiSpecificCode = buildIssueSpecificCorrectCode(id, snippets, failureSummaries);

  const guidanceMap = {
    "color-contrast": {
      recommendation: "1. Identify all text elements with low contrast against their background.\n2. Adjust the foreground or background color to achieve a minimum contrast ratio of 4.5:1 for normal text and 3:1 for large text (18pt+ or 14pt+ bold).\n3. Use a contrast checker tool to verify compliance across all states (hover, focus, active).",
      correctCode: `<style>
.btn-primary {
  color: #ffffff;
  background-color: #005fcc; /* ensure contrast ratio >= 4.5:1 */
}
</style>`
    },
    "label": {
      recommendation: "1. Every form control must have a programmatically associated label.\n2. Use the <label for=\"...\"> attribute to link to the corresponding input id.\n3. Ensure the label text is visible and accurately describes the field's purpose.",
      correctCode: `<!-- Add visible label associated via for/id -->
<label for="email">Email address</label>
<input id="email" name="email" type="email" required>`
    },
    "select-name": {
      recommendation: "1. Locate the <select> element and check if it has an associated <label>.\n2. Add a visible <label> with a 'for' attribute matching the select's 'id'.\n3. If a visible label is not possible, use 'aria-label' or 'aria-labelledby' to provide an accessible name.",
      correctCode: ""
    },
    "image-alt": {
      recommendation: "1. Audit all <img> tags for the presence of an 'alt' attribute.\n2. For informative images, provide a concise description of the image's content or function.\n3. For purely decorative images, use empty alt text (alt=\"\") to hide them from assistive technologies.",
      correctCode: `<img src="/images/support.png" alt="Contact investor support">
<!-- decorative image -->
<img src="/images/divider.svg" alt="">`
    },
    "image-redundant-alt": {
      recommendation: "Avoid repeating surrounding visible text in image alternative text.",
      correctCode: `<!-- If adjacent text already says "Investor Login", keep image decorative -->
<a href="/investor-login">
  <img src="/icons/login.svg" alt="">
  <span>Investor Login</span>
</a>`
    },
    "link-name": {
      recommendation: "1. Review link text to ensure it makes sense out of context (avoid \"click here\" or \"more\").\n2. If the link contains only an icon, add a'visually-hidden' span or 'aria-label' to the <a> tag.\n3. Ensure the accessible name clearly describes the link's destination.",
      correctCode: `<a href="/investor-login">Investor Login</a>`
    },
    "button-name": {
      recommendation: "1. Ensure every <button> has discernible text content.\n2. For icon-based buttons, provide an accessible name using 'aria-label' or a 'visually-hidden' span.\n3. Verify that the button's purpose is clear to screen reader users.",
      correctCode: `<!-- Use visible button text -->
<button type="button">Close dialog</button>

<!-- For icon button: include hidden text node -->
<button type="button" class="icon-btn">
  <span class="visually-hidden">Close dialog</span>
  <i class="icon-close" aria-hidden="true"></i>
</button>`
    },
    "document-title": {
      recommendation: "Set a unique, descriptive <title> for the page to identify its purpose.",
      correctCode: `<head>
  <title>Careers - Samco Mutual Fund</title>
</head>`
    },
    "html-has-lang": {
      recommendation: "Set the document language using the lang attribute on the <html> element.",
      correctCode: `<html lang="en">`
    },
    "html-lang-valid": {
      recommendation: "Use a valid BCP 47 language tag in the html lang attribute.",
      correctCode: `<html lang="en-IN">`
    },
    "heading-order": {
      recommendation: "Use a logical heading hierarchy (h1 to h6) without skipping levels.",
      correctCode: `<h1>Careers</h1>
<h2>Current Openings</h2>
<h3>Frontend Developer</h3>`
    },
    "landmark-one-main": {
      recommendation: "Provide exactly one main landmark to help screen-reader users identify primary page content.",
      correctCode: `<header>...</header>
<main id="main-content">...</main>
<footer>...</footer>`
    },
    "aria-input-field-name": {
      recommendation: "Ensure each form input has a clear accessible name.",
      correctCode: `<label for="full_name">Full name</label>
<input id="full_name" name="full_name" type="text" required>`
    },
    "nested-interactive": {
      recommendation: "Do not place interactive elements inside other interactive elements.",
      correctCode: `<!-- Good: single interactive control -->
<a class="btn btn-primary" href="/apply">Apply Now</a>

<!-- Instead of this invalid pattern -->
<!-- <button><a href="/apply">Apply</a></button> -->`
    },
    "region": {
      recommendation: "Ensure all significant page content is inside semantic landmarks.",
      correctCode: `<header>...</header>
<main id="main-content">...</main>
<footer>...</footer>`
    },
    "presentation-role-conflict": {
      recommendation: "Remove presentational role from focusable/interactive elements and keep native semantics.",
      correctCode: `<!-- Bad: focusable element with role="presentation" -->
<!-- <button type="button" role="presentation" class="owl-prev"><img src="/images/left.png" alt=""></button> -->

<!-- Good: remove presentational role and keep accessible name -->
<button type="button" class="owl-prev">Previous</button>`
    },
    "aria-allowed-role": {
      recommendation: "Use only valid ARIA role values supported for that element.",
      correctCode: `<!-- Bad: unsupported role on input -->
<!-- <input type="text" role="button"> -->

<!-- Good: keep native semantics -->
<input type="text" id="city" name="city">`
    },
    "aria-required-children": {
      recommendation: "When using ARIA composite roles, include all required child roles.",
      correctCode: `<ul role="listbox" aria-label="Department">
  <li role="option" aria-selected="false">Sales</li>
  <li role="option" aria-selected="true">Engineering</li>
</ul>`
    },
    "autocomplete-valid": {
      recommendation: "Use valid autocomplete tokens for input purpose.",
      correctCode: `<label for="email">Email</label>
<input id="email" name="email" type="email" autocomplete="email">`
    },
    "aria-hidden-focus": {
      recommendation: "Do not keep focusable elements inside containers hidden from assistive technologies.",
      correctCode: `<!-- Hidden container should not contain focusable controls -->
<div aria-hidden="true">
  <button tabindex="-1" aria-hidden="true">Hidden action</button>
</div>`
    },
    "target-size": {
      recommendation: "Increase pointer target dimensions for interactive controls.",
      correctCode: `<button class="touch-target">Apply</button>

<style>
.touch-target {
  min-width: 24px;
  min-height: 24px;
  padding: 8px 12px;
}
</style>`
    }
  };

  if (guidanceMap[id]) {
    const out = Object.assign({}, guidanceMap[id]);
    if (id === "label" || id === "aria-input-field-name") {
      const controls = extractTagHintsFromSnippets(snippets, ["input", "select", "textarea"]);
      if (controls.length) {
        out.correctCode = controls.map((c) => {
          const tag = c.tag;
          const idAttr = c.attrs.id || c.attrs.name || `${tag}_field`;
          const nameAttr = c.attrs.name || idAttr;
          const clsAttr = c.attrs.class ? ` class="${c.attrs.class}"` : "";
          const labelText = toLabelText(idAttr, tag);
          if (tag === "select") {
            return `<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}"${clsAttr}>
  <option value="">Select ${labelText}</option>
</select>`;
          }
          if (tag === "textarea") {
            return `<label for="${idAttr}">${labelText}</label>
<textarea id="${idAttr}" name="${nameAttr}"${clsAttr}></textarea>`;
          }
          const typeAttr = c.attrs.type ? ` type="${c.attrs.type}"` : ` type="text"`;
          return `<label for="${idAttr}">${labelText}</label>
<input id="${idAttr}" name="${nameAttr}"${typeAttr}${clsAttr}>`;
        }).join("\n\n");
      }
    }

    if (id === "select-name") {
      const controls = extractControlHintsFromSnippets(snippets, "select");
      if (controls.length) {
        const controlList = controls.map((c) => `"${c.id || c.name || "unnamed-select"}"`).join(", ");
        const stepList = [
          `Add explicit <label for="..."> linked to the <select> id for these controls: ${controlList}.`,
          "Keep label text descriptive, visible, and placed close to the field.",
          "Ensure each <select> has a unique id and each <label for=\"...\"> points to that exact id."
        ];
        out.recommendation = buildDetailedRecommendation(
          "Ensure every <select> element has an accessible name.",
          stepList
        );
        out.correctCode = controls.map((c) => {
          const idAttr = c.id || c.name || "select_field";
          const nameAttr = c.name || idAttr;
          const labelText = idAttr.replace(/[_-]+/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
          return `<!-- Add visible associated label -->
<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}" class="${c.cls || "form-input"}">
  <option value="">Select ${labelText}</option>
</select>`;
        }).join("\n\n");
      } else {
        out.recommendation = buildDetailedRecommendation(
          "Ensure every <select> element has an accessible name.",
          [
            "Add a visible <label for=\"...\"> linked to each affected <select> id.",
            "Keep label text meaningful and visible near the field.",
            "Ensure every <select> id is unique and matches its corresponding <label for=\"...\">."
          ]
        );
        out.correctCode = `<!-- Add visible associated label -->
<label for="department_name">Department</label>
<select id="department_name" name="department_name">
  <option value="">Select Department</option>
</select>`;
      }
    }
    if (id === "button-name") {
      const buttons = extractTagHintsFromSnippets(snippets, ["button", "input"]);
      if (buttons.length) {
        out.correctCode = buttons.map((b) => {
          if (b.tag === "input") {
            const type = (b.attrs.type || "button").toLowerCase();
            if (["button", "submit", "reset"].includes(type)) {
              const value = b.attrs.value || "Submit";
              const idAttr = b.attrs.id ? ` id="${b.attrs.id}"` : "";
              const clsAttr = b.attrs.class ? ` class="${b.attrs.class}"` : "";
              return `<input type="${type}"${idAttr}${clsAttr} value="${value}">`;
            }
          }
          const idAttr = b.attrs.id ? ` id="${b.attrs.id}"` : "";
          const clsAttr = b.attrs.class ? ` class="${b.attrs.class}"` : "";
          const text = toLabelText(b.attrs.id || b.attrs.name || "Button", "Button");
          return `<button type="button"${idAttr}${clsAttr}>${text}</button>`;
        }).join("\n\n");
      }
    }

    if (id === "link-name") {
      const links = extractTagHintsFromSnippets(snippets, ["a"]);
      if (links.length) {
        out.correctCode = links.map((l) => {
          const href = l.attrs.href || "#";
          const clsAttr = l.attrs.class ? ` class="${l.attrs.class}"` : "";
          return `<a href="${href}"${clsAttr}>Descriptive link text</a>`;
        }).join("\n\n");
      }
    }

    if (id === "image-alt" || id === "image-redundant-alt") {
      const imgs = extractTagHintsFromSnippets(snippets, ["img"]);
      if (imgs.length) {
        out.correctCode = imgs.map((img) => {
          const src = img.attrs.src || "/images/example.png";
          const clsAttr = img.attrs.class ? ` class="${img.attrs.class}"` : "";
          return `<img src="${src}" alt="Meaningful description"${clsAttr}>`;
        }).join("\n\n");
      }
    }

    if (id !== "select-name") {
      const commonSteps = [];
      if (id === "color-contrast") {
        commonSteps.push("Adjust text/background colors until contrast reaches at least 4.5:1 for normal text and 3:1 for large text.");
        commonSteps.push("Re-check hover/focus/visited states of links and buttons to keep contrast compliant in all states.");
      } else if (id === "label") {
        commonSteps.push("Add explicit <label for=\"...\"> for each affected form control with unique id/for mapping.");
        commonSteps.push("Do not rely on placeholder as label; keep a persistent visible label for all users.");
        commonSteps.push("Verify clicking/tapping the label focuses the correct field.");
      } else if (id === "image-alt") {
        commonSteps.push("Write meaningful alt text for informative images and use alt=\"\" for decorative images.");
        commonSteps.push("Avoid repeating adjacent text in alt value.");
      } else if (id === "image-redundant-alt") {
        commonSteps.push("If adjacent text already conveys the same meaning, set image alt to empty (alt=\"\").");
        commonSteps.push("Keep one clear text label instead of duplicated text + identical alt.");
      } else if (id === "link-name") {
        commonSteps.push("Ensure each link has clear, unique visible text describing destination/action.");
        commonSteps.push("Avoid generic labels like \"click here\" or \"read more\" without context.");
      } else if (id === "button-name") {
        commonSteps.push("Provide clear visible text for each <button> whenever possible.");
        commonSteps.push("For icon-only buttons, include an in-button hidden text label (e.g., visually-hidden text).");
        commonSteps.push("Ensure button name describes the action (e.g., \"Close dialog\", \"Open menu\").");
      } else if (id === "aria-input-field-name") {
        commonSteps.push("Add a visible label associated with each input control.");
        commonSteps.push("Ensure label and field mapping is unique via for/id.");
      } else if (id === "nested-interactive") {
        commonSteps.push("Keep only one interactive root per control.");
        commonSteps.push("Remove nested links/buttons and keep a single clickable element.");
      } else if (id === "region") {
        commonSteps.push("Wrap major content in semantic landmarks such as <main>, <header>, and <footer>.");
        commonSteps.push("Ensure only one primary <main> landmark is present.");
      } else if (id === "presentation-role-conflict") {
        commonSteps.push("Remove role=\"presentation\"/role=\"none\" from interactive or focusable elements.");
        commonSteps.push("Keep native semantics on links, buttons, and form controls.");
      } else if (id === "aria-allowed-role") {
        commonSteps.push("Use only valid ARIA roles for the given element type.");
        commonSteps.push("Prefer native semantic elements instead of forcing custom roles.");
      } else if (id === "aria-required-children") {
        commonSteps.push("When parent ARIA role is used, include all required child roles.");
        commonSteps.push("Validate role hierarchy and ownership attributes.");
      } else if (id === "autocomplete-valid") {
        commonSteps.push("Use valid autocomplete attribute values (e.g., email, name, tel).");
        commonSteps.push("Match autocomplete token to the actual field purpose.");
      } else if (id === "aria-hidden-focus") {
        commonSteps.push("Do not leave focusable elements inside aria-hidden containers.");
        commonSteps.push("Remove focusability or move control outside hidden container.");
      } else if (id === "target-size") {
        commonSteps.push("Increase size/padding of small controls to minimum touch target.");
        commonSteps.push("Maintain target size across default and responsive breakpoints.");
      } else {
        commonSteps.push("Review affected elements and apply semantic HTML updates required by this rule.");
        commonSteps.push("Retest after fixes and verify no violations remain.");
      }
      out.recommendation = buildDetailedRecommendation(
        String(out.recommendation || defaultRec || "").trim(),
        commonSteps
      );
    }
    out.correctCode = String(aiSpecificCode || out.correctCode || "").trim() || buildGenericCorrectCode(id);
    out.recommendation = appendReferenceLinks(out.recommendation, getApgReferences(id, snippets));
    return out;
  }

  const help = neutralizeToolBranding(String(violation && violation.help || "")).trim();
  const fallbackSteps = [
    "Inspect all flagged elements and apply semantic HTML fix required by this rule.",
    "Validate the fix in keyboard and screen-reader flow.",
    "Run scan again to confirm violation is resolved."
  ];
  return {
    recommendation: appendReferenceLinks(
      buildDetailedRecommendation(help || defaultRec, fallbackSteps),
      getApgReferences(id, snippets)
    ),
    correctCode: String(aiSpecificCode || "").trim() || (function () {
      const generic = buildGenericCorrectCode(id);
      const controls = extractTagHintsFromSnippets(snippets, ["input", "select", "textarea", "button", "a", "img"]);
      if (!controls.length) return generic;
      const first = controls[0];
      if (first.tag === "input") {
        const idAttr = first.attrs.id || first.attrs.name || "field_name";
        const nameAttr = first.attrs.name || idAttr;
        const typeAttr = first.attrs.type || "text";
        const labelText = toLabelText(idAttr, "Field");
        return `<label for="${idAttr}">${labelText}</label>
<input id="${idAttr}" name="${nameAttr}" type="${typeAttr}">`;
      }
      if (first.tag === "select") {
        const idAttr = first.attrs.id || first.attrs.name || "select_field";
        const nameAttr = first.attrs.name || idAttr;
        const labelText = toLabelText(idAttr, "Option");
        return `<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}">
  <option value="">Select ${labelText}</option>
</select>`;
      }
      if (first.tag === "textarea") {
        const idAttr = first.attrs.id || first.attrs.name || "message";
        const nameAttr = first.attrs.name || idAttr;
        const labelText = toLabelText(idAttr, "Message");
        return `<label for="${idAttr}">${labelText}</label>
<textarea id="${idAttr}" name="${nameAttr}"></textarea>`;
      }
      if (first.tag === "button") {
        return `<button type="button">Descriptive action</button>`;
      }
      if (first.tag === "a") {
        return `<a href="${first.attrs.href || "#"}">Descriptive link text</a>`;
      }
      if (first.tag === "img") {
        return `<img src="${first.attrs.src || "/images/example.png"}" alt="Meaningful description">`;
      }
      return generic;
    })()
  };
}

(async function run() {
  const args = parseArgs(process.argv);
  const url = String(args.url || '').trim();
  const outPath = String(args.out || '').trim();
  const screenshotDir = String(args['screenshot-dir'] || '').trim();
  const maxNodesArg = parseInt(args['max-nodes'] || '0', 10) || 0;
  const mode = String(args.mode || 'default').toLowerCase();
  const token = String(args.token || '').trim();

  if (!url || !outPath || !screenshotDir) {
    throw new Error('Missing required args: --url --out --screenshot-dir');
  }

  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.mkdirSync(screenshotDir, { recursive: true });

  const executablePath = findBrowserExecutable();
  if (!executablePath) {
    throw new Error('Chrome/Edge executable not found. Set CHROME_PATH env var.');
  }

  const browser = await puppeteer.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  
  reportStatus(token, { status: 'running', message: `Initializing scan for ${url}...` });
  
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 120000 });

  // Wait a bit for async UI widgets.
  await new Promise((r) => setTimeout(r, 1200));

  let findings = [];
  const feedbackPath = path.join(__dirname, '..', 'storage', 'ai_feedback.json');

  if (mode === 'discovery') {
    // --- MODE: AI Discovery Audit ---
    reportStatus(token, { message: 'Running AI Discovery on page...' });
    findings = await runAIDiscoveryAudit(page, feedbackPath);
  } else {
    // --- MODE: Default (axe-core + AI Enhancement) ---
    await page.evaluate((source) => {
      // eslint-disable-next-line no-eval
      eval(source);
    }, axeSource);

    const scanResult = await page.evaluate(async () => {
      return await axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa', 'best-practice'] },
        resultTypes: ['violations']
      });
    });

    const isAiEnhanceMode = (mode === 'ai_enhance');
    const msg = isAiEnhanceMode 
        ? `Engine found ${(scanResult.violations || []).length} violation types. Enhancing with AI...`
        : `Engine found ${(scanResult.violations || []).length} violation types. Processing snapshots...`;
    reportStatus(token, { message: msg });

    const violations = scanResult.violations || [];
    const CONCURRENCY = 3; // AI processing concurrency
    const aiResultsMap = new Map();

    // --- PHASE 1: Parallel AI Recommendations ---
    if (isAiEnhanceMode) {
      for (let i = 0; i < violations.length; i += CONCURRENCY) {
        const batch = violations.slice(i, i + CONCURRENCY);
        await Promise.all(batch.map(async (v) => {
            const allNodes = v.nodes || [];
            const nodes = maxNodesArg > 0 ? allNodes.slice(0, maxNodesArg) : allNodes;
            const snippet = nodes[0] ? String(nodes[0].html || '').trim() : '';
            
            try {
                reportStatus(token, { message: `AI Analyzing: ${v.id}...` });
                const aiData = await getAIEnhancedFindings(v, snippet, feedbackPath, token);
                if (aiData) {
                    aiResultsMap.set(v.id, aiData);
                }
            } catch (_) {}
        }));
      }
    }

    // --- PHASE 2: Sequential Puppeteer Processing ---
    let screenshotSeqTotal = 1;
    for (const violation of violations) {
      const allNodes = violation.nodes || [];
      const nodes = maxNodesArg > 0 ? allNodes.slice(0, maxNodesArg) : allNodes;
      const snippets = uniq(nodes.map((n) => String(n.html || '').trim()).filter(Boolean));
      const failureSummaries = uniq(nodes.map((n) => String(n.failureSummary || "").replace(/\s+/g, " ").trim()).filter(Boolean));
      const guidance = getRuleSpecificGuidance(violation, { snippets, failureSummaries });
      
      const aiData = aiResultsMap.get(violation.id);
      const recommendation = (aiData && aiData.recommendation) ? aiData.recommendation : guidance.recommendation;
      const aiActualResults = (aiData && aiData.actual_results) ? aiData.actual_results : null;
      const aiIncorrectCode = (aiData && aiData.incorrect_code) ? aiData.incorrect_code : null;
      const aiCorrectCode = (aiData && aiData.correct_code) ? aiData.correct_code : guidance.correctCode;

      const nodeInputs = nodes
        .map((n) => ({
          selector: (Array.isArray(n.target) ? n.target[0] : "") || "",
          failure_summary: String(n.failureSummary || "").replace(/\s+/g, " ").trim()
        }))
        .filter((x) => x.selector);

      const instanceContext = await page.evaluate((nodeList) => {
        function getInstanceName(el) {
          if (!el) return "";
          const aria = (el.getAttribute("aria-label") || "").trim();
          if (aria) return aria;
          const alt = (el.getAttribute("alt") || "").trim();
          if (alt) return alt;
          const title = (el.getAttribute("title") || "").trim();
          if (title) return title;
          const nameAttr = (el.getAttribute("name") || "").trim();
          if (nameAttr) return nameAttr;
          const id = (el.id || "").trim();
          if (id) return `#${id}`;
          const text = (el.innerText || el.textContent || "").replace(/\s+/g, " ").trim();
          if (text) return text.slice(0, 80);
          return el.tagName ? el.tagName.toLowerCase() : "element";
        }
        function getSectionContext(el) {
          if (!el) return "page section";
          const directSection = el.closest("header, nav, main, footer, aside, section, article, form, [role='region'], [role='main'], [role='navigation']");
          if (directSection) {
            if (directSection.tagName && directSection.tagName.toLowerCase() === "footer") return "Footer section";
            const heading = directSection.querySelector("h1, h2, h3, h4, h5, h6");
            if (heading) {
              const hText = (heading.innerText || heading.textContent || "").replace(/\s+/g, " ").trim();
              if (hText) return hText.slice(0, 90);
            }
            if (directSection.id) return `section#${directSection.id}`;
            if (directSection.getAttribute("aria-label")) return directSection.getAttribute("aria-label").trim();
            return directSection.tagName.toLowerCase();
          }
          return "page section";
        }
        const out = [];
        for (const item of nodeList || []) {
          const selector = String(item && item.selector ? item.selector : "").trim();
          if (!selector) continue;
          const el = document.querySelector(selector);
          if (!el) continue;
          out.push({
            selector,
            failure_summary: String(item && item.failure_summary ? item.failure_summary : "").trim(),
            instance_name: getInstanceName(el),
            section_context: getSectionContext(el),
            abs_top: Math.max(0, (window.scrollY || 0) + (el.getBoundingClientRect().top || 0))
          });
        }
        return out;
      }, nodeInputs);

      const groupedFailureMap = new Map();
      for (const item of (instanceContext || [])) {
        const summary = simplifyFailureSummary(item && item.failure_summary ? item.failure_summary : "", violation.id);
        const key = summary || "__default__";
        if (!groupedFailureMap.has(key)) groupedFailureMap.set(key, { summary, instances: [] });
        groupedFailureMap.get(key).instances.push(item);
      }
      const groupedFailures = Array.from(groupedFailureMap.values()).map((g) => {
        const seenInst = new Set();
        const outInst = [];
        for (const inst of (g.instances || [])) {
          const name = String(inst.instance_name || "Unnamed element").trim();
          const section = String(inst.section_context || "page section").trim();
          const dedupKey = `${name.toLowerCase()}||${section.toLowerCase()}`;
          if (seenInst.has(dedupKey)) continue;
          seenInst.add(dedupKey);
          outInst.push({
            selector: inst.selector,
            instance_name: name,
            section_context: section,
            abs_top: inst.abs_top
          });
        }
        return { summary: g.summary, instances: outInst };
      });

      const viewportHeight = 900;
      const screenshotInstances = [];
      const seenScreenshotSelectors = new Set();
      for (const inst of (instanceContext || [])) {
        const sel = String(inst && inst.selector ? inst.selector : "").trim();
        if (!sel || seenScreenshotSelectors.has(sel)) continue;
        seenScreenshotSelectors.add(sel);
        screenshotInstances.push(inst);
      }
      const sortedInstances = screenshotInstances.slice().sort((a, b) => Number(a.abs_top || 0) - Number(b.abs_top || 0));
      const chunks = [];
      for (const inst of sortedInstances) {
        const top = Number(inst.abs_top || 0);
        const last = chunks[chunks.length - 1];
        if (!last || Math.abs(top - last.anchorTop) > Math.floor(viewportHeight * 0.75)) {
          chunks.push({ anchorTop: top, selectors: [inst.selector] });
        } else {
          last.selectors.push(inst.selector);
        }
      }

      const screenshots = [];
      for (const chunk of chunks) {
        try {
          await page.evaluate((selectorList) => {
            document.querySelectorAll('[data-pms-a11y-focus="1"]').forEach((el) => {
              el.style.outline = ""; el.style.outlineOffset = ""; el.style.boxShadow = "";
              el.removeAttribute("data-pms-a11y-focus");
            });
            let firstEl = null;
            (selectorList || []).forEach((selector) => {
              const el = document.querySelector(selector);
              if (!el) return;
              if (!firstEl) firstEl = el;
              el.setAttribute("data-pms-a11y-focus", "1");
              el.style.outline = "3px solid #d90429"; el.style.outlineOffset = "2px";
              el.style.boxShadow = "0 0 0 3px rgba(217,4,41,0.25)";
            });
            if (firstEl && firstEl.scrollIntoView) firstEl.scrollIntoView({ behavior: "instant", block: "center", inline: "center" });
          }, chunk.selectors);
          await new Promise((r) => setTimeout(r, 150));
          const fileName = `${String(Date.now())}_${sanitizeName(violation.id)}_${screenshotSeqTotal++}.png`;
          const absShot = path.join(screenshotDir, fileName);
          await page.screenshot({ path: absShot, fullPage: false });
          screenshots.push(fileName);
        } catch (_) {}
      }

      const actualResultsRaw = aiActualResults || formatActualResults(url, String(violation.description || "").trim(), groupedFailures, recommendation, String(violation.id || "").trim());
      const wcagMeta = extractWcagMeta(violation);

      findings.push({
        rule_id: String(violation.id || '').trim(),
        title: String(violation.help || violation.id || 'Accessibility issue').trim(),
        severity: String(violation.impact || 'moderate').trim(),
        needs_review_severity: toNeedsReviewSeverity(String(violation.impact || '')),
        wcag_sc: wcagMeta.scList,
        wcag_name: wcagMeta.wcagName,
        wcag_level: wcagMeta.level,
        actual_results: actualResultsRaw,
        incorrect_code: aiIncorrectCode || snippets.join('\n\n'),
        screenshots,
        recommendation,
        correct_code: String(aiCorrectCode || guidance.correctCode || '').trim(),
        help_url: '',
        occurrence_count: Number(violation.nodes ? violation.nodes.length : 0) || 0,
        raw_nodes: nodeInputs
      });
    }
  }

  await page.close();
  await browser.close();

  const summary = {
    issues: findings.length,
    critical: findings.filter((f) => (f.severity || '').toLowerCase() === 'critical').length,
    serious: findings.filter((f) => (f.severity || '').toLowerCase() === 'serious').length,
    moderate: findings.filter((f) => (f.severity || '').toLowerCase() === 'moderate').length,
    minor: findings.filter((f) => (f.severity || '').toLowerCase() === 'minor').length
  };

  fs.writeFileSync(outPath, JSON.stringify({ success: true, url, summary, findings }, null, 2), 'utf8');
})().catch((err) => {
  const msg = err && err.stack ? err.stack : String(err);
  try {
    const args = parseArgs(process.argv);
    if (args.out) {
      fs.mkdirSync(path.dirname(args.out), { recursive: true });
      fs.writeFileSync(args.out, JSON.stringify({ success: false, error: msg }, null, 2), 'utf8');
    }
  } catch (_) {}
  console.error(msg);
  process.exit(1);
});
