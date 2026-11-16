/** The answer of POST /api/admin/orders/mark-exported (docs/API.md). */
export interface MarkExportedAnswer {
    exported: string[];
    skipped: { number: string; reason: string }[];
}

/** Why the server refused to mark the order(s), in its own words; null when nothing was refused. */
export function markRefusal(answer: MarkExportedAnswer): string | null {
    return answer.skipped.length > 0 ? answer.skipped.map((skip) => skip.reason).join(' ') : null;
}
