import './bootstrap';
import '@hotwired/turbo';
import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';

// flatpickr
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
// FullCalendar
import { Calendar } from '@fullcalendar/core';
// SweetAlert2
import Swal from 'sweetalert2';
// Email template workspace (Alpine component used by resources/views/pages/email-templates)
import { emailWorkspace } from './components/email-workspace';



window.Alpine = Alpine;
window.ApexCharts = ApexCharts;
window.flatpickr = flatpickr;
window.FullCalendar = Calendar;
window.Swal = Swal;
window.emailWorkspace = emailWorkspace;

Alpine.start();

// Initialize page-specific components. Runs on the very first page load
// and again after every Turbo-driven navigation (Turbo swaps the <body>
// without a full reload, so DOMContentLoaded only ever fires once).
document.addEventListener('turbo:load', () => {
    // Map imports
    if (document.querySelector('#mapOne')) {
        import('./components/map').then(module => module.initMap());
    }

    // Chart imports
    if (document.querySelector('#chartOne')) {
        import('./components/chart/chart-1').then(module => module.initChartOne());
    }
    if (document.querySelector('#chartTwo')) {
        import('./components/chart/chart-2').then(module => module.initChartTwo());
    }
    if (document.querySelector('#chartThree')) {
        import('./components/chart/chart-3').then(module => module.initChartThree());
    }

    // Calendar init
    if (document.querySelector('#calendar')) {
        import('./components/calendar-init').then(module => module.calendarInit());
    }

    // Summernote (email template editor) init
    if (document.querySelector('.summernote')) {
        import('./components/summernote-init').then(module => module.summernoteInit());
    }
});

// Tear down stateful jQuery widgets before Turbo snapshots the page for its
// cache, so a restored snapshot never shows a "live-looking" but dead editor.
document.addEventListener('turbo:before-cache', () => {
    if (document.querySelector('.summernote')) {
        import('./components/summernote-init').then(module => module.summernoteDestroy());
    }
});
