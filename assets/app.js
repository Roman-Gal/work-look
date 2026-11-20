import './stimulus_bootstrap.js';
import { session } from '@hotwired/turbo';
// Full-page navigation keeps per-response CSP nonces aligned; progress uses Turbo Frames.
session.drive = false;
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
