import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';


// Installable app (PWA): the service worker shows an offline page when there's no connection.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
