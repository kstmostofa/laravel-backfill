import { defineConfig } from 'vitepress'

export default defineConfig({
    title: 'Laravel Backfill',
    description: 'Safe, resumable, one-off data backfills for Laravel.',
    lang: 'en-GB',
    cleanUrls: true,
    lastUpdated: true,

    // Contributor notes for this folder, not a page. Left in, it would fight
    // index.md over which file is the site root.
    srcExclude: ['README.md'],

    // Published to https://<user>.github.io/laravel-backfill/. Set to '/' if
    // you serve the docs from a domain root instead.
    base: '/laravel-backfill/',

    head: [
        ['meta', { name: 'theme-color', content: '#2f6feb' }],
        ['meta', { property: 'og:title', content: 'Laravel Backfill' }],
        [
            'meta',
            {
                property: 'og:description',
                content: 'Safe, resumable, one-off data backfills for Laravel.',
            },
        ],
    ],

    themeConfig: {
        nav: [
            { text: 'Guide', link: '/guide/introduction' },
            { text: 'Reference', link: '/reference/commands' },
            {
                text: 'v0.4',
                items: [
                    { text: 'Changelog', link: '/changelog' },
                    {
                        text: 'Packagist',
                        link: 'https://packagist.org/packages/kstmostofa/laravel-backfill',
                    },
                ],
            },
        ],

        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Introduction', link: '/guide/introduction' },
                    { text: 'Installation', link: '/guide/installation' },
                    { text: 'Writing a backfill', link: '/guide/writing-a-backfill' },
                    { text: 'Running a backfill', link: '/guide/running' },
                    { text: 'Local development', link: '/guide/local-development' },
                ],
            },
            {
                text: 'How it stays safe',
                items: [
                    { text: 'The invariant', link: '/safety/invariant' },
                    { text: 'Keyset pagination', link: '/safety/keyset-pagination' },
                    { text: 'Transactions and savepoints', link: '/safety/transactions' },
                    { text: 'Failures and retries', link: '/safety/failures' },
                    { text: 'Throttling', link: '/safety/throttling' },
                    { text: 'Production guards', link: '/safety/guards' },
                ],
            },
            {
                text: 'Features',
                items: [
                    { text: 'The dry run', link: '/features/dry-run' },
                    { text: 'Running on the queue', link: '/features/queue' },
                    { text: 'The dashboard', link: '/features/dashboard' },
                    { text: 'The operator panel', link: '/features/operator-panel' },
                    { text: 'Pulse card', link: '/features/pulse' },
                    { text: 'Events', link: '/features/events' },
                    { text: 'Notifications', link: '/features/notifications' },
                ],
            },
            {
                text: 'Advanced',
                items: [
                    { text: 'External side effects', link: '/advanced/side-effects' },
                    { text: 'Multi-tenancy', link: '/advanced/multi-tenancy' },
                    { text: 'Testing your backfills', link: '/advanced/testing' },
                ],
            },
            {
                text: 'Reference',
                items: [
                    { text: 'Commands', link: '/reference/commands' },
                    { text: 'Backfill API', link: '/reference/backfill-api' },
                    { text: 'Parameters', link: '/reference/parameters' },
                    { text: 'Configuration', link: '/reference/configuration' },
                    { text: 'Database schema', link: '/reference/schema' },
                ],
            },
        ],

        socialLinks: [
            { icon: 'github', link: 'https://github.com/kstmostofa/laravel-backfill' },
        ],

        search: {
            provider: 'local',
        },

        editLink: {
            pattern:
                'https://github.com/kstmostofa/laravel-backfill/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        footer: {
            message: 'Released under the MIT Licence.',
            copyright: 'Copyright © 2026 Md Mostafijur Rahman',
        },

        outline: [2, 3],
    },
})
