<?php

declare(strict_types=1);

return [
    'cluster' => 'Settings',
    'general' => [
        'title' => 'General',
        'subheading' => 'Base configuration used across the app.',
        'section_description' => 'These settings affect the whole system.',
        'action_reset_default' => 'Reset to default',
        'site_name' => 'Site name',
        'site_name_helper' => 'Shown in the app header and emails.',
        'site_description' => 'Site description',
        'site_description_helper' => 'Short tagline for landing pages and SEO.',
        'timezone' => 'Timezone',
        'timezone_helper' => 'Used for displaying dates & scheduling.',
        'locale' => 'Locale',
        'locale_helper' => 'Default language for the app.',
        'saved' => 'General settings updated.',
        'reset' => 'Values were reset. Click Save changes to apply.',
    ],
    'media' => [
        'title' => 'Media',
        'subheading' => 'Media configuration and image conversion settings.',
        'section_conversion' => 'Image Conversion',
        'section_conversion_desc' => 'Configure automatic image conversion to modern formats.',
        'convert_format' => 'Default conversion format',
        'convert_format_helper' => 'Only one format can be selected for automatic conversion when uploading.',
        'formats' => [
            'none' => 'No conversion',
            'webp' => 'WebP (Well supported)',
            'avif' => 'AVIF (Best compression - Requires PHP GD support)',
        ],
        'descriptions' => [
            'none' => 'Keep the original format of the image.',
            'webp' => 'A modern image format with superior compression compared to JPEG and PNG.',
            'avif' => 'The most efficient image compression format today, but may not be supported by some older browsers or servers.',
        ],
        'saved' => 'Media settings have been saved.',
    ],
    'save_change' => 'Save changes',
    'saved' => 'Settings saved',
    'reset' => 'Reset',
];
