import './styles/app.css';
import { initPaperPlane } from './js/paper-plane.js';
import { initHome } from './js/home.js';
import { initRegistration } from './js/registration.js';
import { initRecovery } from './js/recovery.js';
import { initMyPlanes } from './js/my-planes.js';
import { initAgents } from './js/agents.js';
import { initSkyPulse } from './js/sky-pulse.js';

document.addEventListener('DOMContentLoaded', () => {
    initPaperPlane();
    initRegistration();
    initRecovery();
    initHome();
    initMyPlanes();
    initAgents();
    initSkyPulse();
});
