import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({ gfm: true, breaks: false });

/**
 * Render Markdown from task prompts, resources and assistant replies. Output is
 * always sanitised because assistant text is model-generated.
 */
export function renderMarkdown(source) {
    if (!source) {
        return '';
    }

    return DOMPurify.sanitize(marked.parse(String(source)), {
        FORBID_TAGS: ['style', 'iframe', 'form', 'input', 'img'],
        FORBID_ATTR: ['style', 'onerror', 'onclick'],
    });
}
