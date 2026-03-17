import os
import json
from typing import Any, Dict, List


def refine_with_ai(
    url: str,
    sections: List[Dict[str, Any]],
    ai_config: Dict[str, Any] | None = None,
) -> List[Dict[str, Any]]:
    """
    Entry point for AI-based refinement.
    - Input: list of section-level groups (output from postprocess_groups)
    - Output: same list, possibly reordered and with optional 'pattern' labels updated.
    """
    if not sections or not ai_config or not ai_config.get("enabled"):
        return sections

    provider = (ai_config.get("provider") or "openai").lower()
    if provider == "openai":
        return _safe_refine(refine_with_openai, url, sections, ai_config)
    if provider == "anthropic":
        return _safe_refine(refine_with_anthropic, url, sections, ai_config)

    return sections


def _safe_refine(
    fn,
    url: str,
    sections: List[Dict[str, Any]],
    ai_config: Dict[str, Any],
) -> List[Dict[str, Any]]:
    try:
        refined = fn(url, sections, ai_config)
        # Basic sanity: keep shape if provider misbehaves
        return refined if isinstance(refined, list) and len(refined) == len(sections) else sections
    except Exception:
        return sections


def test_ai_connection(ai_config: Dict[str, Any]) -> Dict[str, Any]:
    """
    Simple test helper used by the /ai-test endpoint.
    Sends a tiny prompt ("Are you ready?") to the configured provider/model
    and returns either the model's reply or an error message.
    """
    provider = (ai_config.get("provider") or "").lower()
    if provider == "openai":
        return _test_openai(ai_config)
    if provider == "anthropic":
        return _test_anthropic(ai_config)
    return {
        "success": False,
        "provider": provider or "none",
        "error": "Unsupported or missing AI provider",
    }


def _build_section_summaries(sections: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    summaries: List[Dict[str, Any]] = []

    for idx, section in enumerate(sections):
        section_id = section.get("section_id") or f"idx-{idx}"
        children = section.get("children", [])

        heading = None
        text_snippet = None

        for child in children:
            if not heading and child.get("type") == "text" and str(child.get("element", "")).lower() in {
                "h1",
                "h2",
                "h3",
            }:
                heading = child.get("text", "")
            if not text_snippet and child.get("type") == "text":
                text_snippet = child.get("text", "")
            if heading and text_snippet:
                break

        # Truncate for prompt safety
        if heading and len(heading) > 200:
            heading = heading[:200]
        if text_snippet and len(text_snippet) > 300:
            text_snippet = text_snippet[:300]

        summaries.append(
            {
                "section_id": section_id,
                "index": idx,
                "pattern": section.get("pattern") or None,
                "heading": heading,
                "text": text_snippet,
            }
        )

    return summaries


def _apply_ai_plan(sections: List[Dict[str, Any]], plan: Dict[str, Any]) -> List[Dict[str, Any]]:
    by_id = {}
    for idx, section in enumerate(sections):
        sid = section.get("section_id") or f"idx-{idx}"
        by_id[sid] = (idx, section)

    ordered_ids = []
    for entry in plan.get("sections", []):
        sid = entry.get("section_id")
        if sid in by_id:
            ordered_ids.append(sid)

    # Fallback to original order if plan empty/bad
    if not ordered_ids:
        return sections

    # Build new ordered list and apply labels if present
    new_sections: List[Dict[str, Any]] = []
    for entry in plan.get("sections", []):
        sid = entry.get("section_id")
        if sid not in by_id:
            continue
        _, section = by_id[sid]
        label = entry.get("label")
        if label:
            section["pattern"] = label
        new_sections.append(section)

    # Append any sections not mentioned by the plan in original order
    planned_ids = {e.get("section_id") for e in plan.get("sections", [])}
    for idx, section in enumerate(sections):
        sid = section.get("section_id") or f"idx-{idx}"
        if sid not in planned_ids:
            new_sections.append(section)

    return new_sections


def refine_with_openai(
    url: str,
    sections: List[Dict[str, Any]],
    ai_config: Dict[str, Any],
) -> List[Dict[str, Any]]:
    try:
        from openai import OpenAI
    except ImportError:
        return sections

    api_key = ai_config.get("api_key") or os.getenv("OPENAI_API_KEY")
    if not api_key:
        # No key configured; skip AI refinement gracefully
        return sections
    client = OpenAI(api_key=api_key)

    summaries = _build_section_summaries(sections)
    prompt_obj = {
        "url": url,
        "sections": summaries,
    }

    system_msg = (
        "You are a careful website content structurer. "
        "You receive a list of page sections that are already grouped by the scraper. "
        "Your ONLY tasks are:\n"
        "1) Optionally assign a semantic label to each section (hero, services, testimonials, faq, contact, footer, other).\n"
        "2) Optionally propose a better section order.\n"
        "You MUST NOT drop or duplicate sections. "
        "Return ONLY JSON as described."
    )

    user_msg = (
        "Here is the page structure:\n\n"
        f"{json.dumps(prompt_obj, ensure_ascii=False)}\n\n"
        "Respond with a JSON object of the form:\n"
        '{ "sections": [ { "section_id": string, "label": string, "order": integer } ] }\n'
        "Use the original section_id values. "
        'Use one of these labels: "hero", "services", "testimonials", "faq", "contact", "footer", "other". '
        "If you are unsure, use \"other\". Order should start at 1."
    )

    model_name = ai_config.get("model") or os.getenv("OPENAI_MODEL", "gpt-4.1-mini")

    resp = client.chat.completions.create(
        model=model_name,
        messages=[
            {"role": "system", "content": system_msg},
            {"role": "user", "content": user_msg},
        ],
        temperature=0.1,
    )

    content = resp.choices[0].message.content
    plan = json.loads(content)

    # Normalize by order
    if "sections" in plan:
        plan["sections"] = sorted(
            plan["sections"],
            key=lambda x: x.get("order", 10_000),
        )

    return _apply_ai_plan(sections, plan)


def refine_with_anthropic(
    url: str,
    sections: List[Dict[str, Any]],
    ai_config: Dict[str, Any],
) -> List[Dict[str, Any]]:
    try:
        import anthropic
    except ImportError:
        return sections

    api_key = ai_config.get("api_key") or os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        # No key configured; skip AI refinement gracefully
        return sections
    client = anthropic.Anthropic(api_key=api_key)

    summaries = _build_section_summaries(sections)
    prompt_obj = {
        "url": url,
        "sections": summaries,
    }

    system_msg = (
        "You are a careful website content structurer. "
        "You receive a list of page sections that are already grouped by the scraper. "
        "Your ONLY tasks are:\n"
        "1) Optionally assign a semantic label to each section (hero, services, testimonials, faq, contact, footer, other).\n"
        "2) Optionally propose a better section order.\n"
        "You MUST NOT drop or duplicate sections. "
        "Return ONLY JSON as described."
    )

    user_msg = (
        "Here is the page structure:\n\n"
        f"{json.dumps(prompt_obj, ensure_ascii=False)}\n\n"
        "Respond with a JSON object of the form:\n"
        '{ "sections": [ { "section_id": string, "label": string, "order": integer } ] }\n'
        "Use the original section_id values. "
        'Use one of these labels: "hero", "services", "testimonials", "faq", "contact", "footer", "other". '
        "If you are unsure, use \"other\". Order should start at 1."
    )

    model_name = ai_config.get("model") or os.getenv(
        "ANTHROPIC_MODEL", "claude-haiku-4-5"
    )

    resp = client.messages.create(
        model=model_name,
        max_tokens=2048,
        system=system_msg,
        messages=[
            {"role": "user", "content": user_msg},
        ],
    )

    # Anthropics returns a list of content blocks; we expect a single text block
    text = ""
    for block in resp.content:
        if block.type == "text":
            text += block.text

    plan = json.loads(text)

    if "sections" in plan:
        plan["sections"] = sorted(
            plan["sections"],
            key=lambda x: x.get("order", 10_000),
        )

    return _apply_ai_plan(sections, plan)


def _test_openai(ai_config: Dict[str, Any]) -> Dict[str, Any]:
    try:
        from openai import OpenAI
    except ImportError:
        return {"success": False, "provider": "openai", "error": "openai library not installed"}

    api_key = ai_config.get("api_key") or os.getenv("OPENAI_API_KEY")
    if not api_key:
        return {"success": False, "provider": "openai", "error": "Missing OpenAI API key"}

    client = OpenAI(api_key=api_key)
    model_name = ai_config.get("model") or os.getenv("OPENAI_MODEL", "gpt-4.1-mini")

    prompt = "Are you ready?"

    try:
        resp = client.chat.completions.create(
            model=model_name,
            messages=[
                {"role": "system", "content": "You are a helpful assistant."},
                {"role": "user", "content": prompt},
            ],
            temperature=0.1,
            max_tokens=64,
        )
        content = resp.choices[0].message.content
        return {"success": True, "provider": "openai", "model": model_name, "reply": content}
    except Exception as e:
        return {
            "success": False,
            "provider": "openai",
            "model": model_name,
            "error": str(e),
        }


def _test_anthropic(ai_config: Dict[str, Any]) -> Dict[str, Any]:
    try:
        import anthropic
    except ImportError:
        return {"success": False, "provider": "anthropic", "error": "anthropic library not installed"}

    api_key = ai_config.get("api_key") or os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        return {"success": False, "provider": "anthropic", "error": "Missing Anthropic API key"}

    client = anthropic.Anthropic(api_key=api_key)
    model_name = ai_config.get("model") or os.getenv(
        "ANTHROPIC_MODEL", "claude-haiku-4-5"
    )

    prompt = "Are you ready?"

    try:
        resp = client.messages.create(
            model=model_name,
            max_tokens=64,
            system="You are a helpful assistant.",
            messages=[
                {"role": "user", "content": prompt},
            ],
        )

        text = ""
        for block in resp.content:
            if block.type == "text":
                text += block.text

        return {
            "success": True,
            "provider": "anthropic",
            "model": model_name,
            "reply": text,
        }
    except Exception as e:
        return {
            "success": False,
            "provider": "anthropic",
            "model": model_name,
            "error": str(e),
        }

