import axios from 'axios';

/**
 * Shared HTTP client for the SPA. Uses Sanctum cookie auth:
 *  - `withCredentials` sends the session + XSRF cookies on every request
 *  - `withXSRFToken` echoes the XSRF-TOKEN cookie back as the X-XSRF-TOKEN
 *    header (required by Laravel's CSRF protection on stateful requests)
 *
 * Call `GET /sanctum/csrf-cookie` once before the first mutating request.
 */
export const api = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
    },
});
