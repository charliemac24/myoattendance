import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/people.js', 'resources/js/groups.js', 'resources/js/subgroups.js', 'resources/js/schedules.js', 'resources/js/person-details.js', 'resources/js/attendance.js', 'resources/js/attendance-monthly.js', 'resources/js/needs-attention.js', 'resources/js/timesheet.js', 'resources/js/billing.js', 'resources/js/scanner.js', 'resources/js/sms-templates.js', 'resources/js/terminology.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
