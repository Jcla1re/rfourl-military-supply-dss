<?php // Demo mode only (DEMO_MODE=true): keep the public demo out of search engines and make room for the banner. ?>
<?php if (demo_mode()): ?>
    <meta name="robots" content="noindex, nofollow">
    <link href="/assets/css/demo.css" rel="stylesheet">
<?php endif; ?>
