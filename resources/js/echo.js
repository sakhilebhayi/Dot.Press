import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb speaks the Pusher protocol, so laravel-echo's 'reverb' broadcaster
// is really just its Pusher connector pointed at our own server. Only
// initialize it when the key AND host are configured at build time.
// Production (shared cPanel host) has no websocket server and runs
// BROADCAST_CONNECTION=null with no VITE_REVERB_* values, so Echo must not
// exist there: constructing it would retry a websocket to a dead host on
// every page. See .env.example.
if (import.meta.env.VITE_REVERB_APP_KEY && import.meta.env.VITE_REVERB_HOST) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            withCredentials: true,
        },
    });

    // SlideController::update broadcasts with ->toOthers(), which reads
    // this header to know which connection to skip -- without it, saving
    // your own edit would also echo back to yourself as a "remote" update.
    window.Echo.connector.pusher.connection.bind('connected', () => {
        if (window.axios) {
            window.axios.defaults.headers.common['X-Socket-Id'] = window.Echo.socketId();
        }
    });
}
