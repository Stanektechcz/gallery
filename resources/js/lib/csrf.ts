/**
 * Get the CSRF token from the meta tag or cookie.
 * This is used for non-Sanctum web route requests.
 */
export function getCsrfToken(): string {
    // Try meta tag first (set by Laravel blade)
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    );
    if (meta?.content) return meta.content;

    // Fall back to XSRF-TOKEN cookie (used by Sanctum stateful)
    const cookie = document.cookie
        .split(";")
        .find((c) => c.trim().startsWith("XSRF-TOKEN="));

    if (cookie) {
        return decodeURIComponent(cookie.split("=").slice(1).join("="));
    }

    return "";
}

/**
 * Perform a POST/DELETE/PATCH request with CSRF protection.
 * For web routes (not /api/v1/), use this instead of raw fetch.
 */
export async function csrfFetch(
    url: string,
    method: "POST" | "DELETE" | "PATCH" | "PUT" = "POST",
    body?: Record<string, unknown>,
): Promise<Response> {
    // Ensure we have an XSRF cookie from Sanctum
    const hasCookie = document.cookie.includes("XSRF-TOKEN");
    if (!hasCookie) {
        await fetch("/sanctum/csrf-cookie", { credentials: "include" });
    }

    const xsrf = getCsrfToken();

    return fetch(url, {
        method,
        credentials: "include",
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
}
