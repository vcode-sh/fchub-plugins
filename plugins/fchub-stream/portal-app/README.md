# FCHub Stream - Portal Integration

Vue 3 frontend application for video upload functionality in FluentCommunity Portal.

## Overview

This is the portal-facing frontend that integrates with FluentCommunity Portal to provide video upload functionality. Users can upload videos directly from the Portal composer, and videos are processed and displayed using Cloudflare Stream or Bunny.net Stream.

## Technology Stack

- **Vue 3.5.22** - Composition API
- **Vite 7.1.12** - Build tool
- **Axios 1.13.1** - HTTP client
- **IIFE format** - Browser-compatible bundle

## Project Structure

```
portal-app/
├── src/
│   ├── main.js                      # Entry point
│   ├── composables/                 # Vue 3 composables
│   │   ├── useVideoUpload.js        # Upload state & logic
│   │   ├── useVideoStatus.js        # Status polling
│   │   └── usePortalIntegration.js  # Portal utilities
│   ├── components/                  # Vue components
│   │   ├── VideoUploadButton.vue    # Upload button
│   │   ├── VideoUploadDialog.vue    # Upload modal
│   │   ├── VideoUploadProgress.vue  # Progress bar
│   │   ├── VideoPreview.vue         # Video thumbnail
│   │   └── VideoPlayer.vue          # Video player
│   ├── services/                    # API services
│   │   ├── uploadService.js         # Upload API
│   │   └── statusService.js         # Status API
│   └── utils/                       # Utilities
│       ├── fileValidation.js        # File validation
│       └── constants.js             # Constants
├── dist/                            # Build output
│   └── fchub-stream-portal.js       # Production bundle
├── package.json                     # Dependencies
└── vite.config.js                   # Vite configuration
```

## Development

### Installation

```bash
npm install
```

### Development Server

```bash
npm run dev
```

### Production Build

```bash
npm run build
```

Output: `dist/fchub-stream-portal.js` (~125KB, ~49KB gzipped)

## Features

### Upload Components

- **VideoUploadButton** - Button in Portal composer with upload indicator
- **VideoUploadDialog** - Full-featured upload modal with:
  - Drag & drop file selection
  - File picker (click to browse)
  - File validation (size, type, extension)
  - Upload progress with speed & time estimates
  - Error handling with retry
  - Auto-close on success

### Video Components

- **VideoPreview** - Thumbnail display during encoding with:
  - Encoding status overlay (spinner + text)
  - Play button when ready
  - 16:9 aspect ratio

- **VideoPlayer** - Responsive video player with:
  - Loading overlay
  - Error handling with retry
  - Fullscreen support
  - Provider-agnostic (Cloudflare, Bunny.net)

### Upload Features

- Real-time progress tracking
- Upload speed calculation (MB/s)
- Time remaining estimates
- Upload cancellation (AbortController)
- File validation (size, type, extension)
- Error handling with retry
- Shortcode insertion after upload

### Status Polling

- Automatic polling with configurable interval (default 5s)
- Auto-stop when video ready or failed
- Cleanup on component unmount
- Error handling

## Portal Integration

### PHP Hooks

The portal-app integrates with FluentCommunity Portal via PHP hooks in `app/Hooks/PortalIntegration.php`:

- **fluent_community/portal_data_vars** - Injects JavaScript bundle
- **fluent_community/portal_vars** - Injects settings (fchubStreamSettings)
- **fluent_community/feed/new_feed_data** - Processes shortcodes before save
- **fluent_community/feed_api_response** - Processes shortcodes in existing posts
- **fluent_community/support_attachment_types** - Adds video MIME types
- **wp_kses_allowed_html** - Allows iframe in content

### Portal Settings

Injected via `window.fchubStreamSettings`:

```javascript
{
  enabled: true,
  provider: 'cloudflare_stream' | 'bunny_stream',
  rest_url: '/wp-json/fluent-community/v2/stream',
  rest_nonce: 'wp_rest_...',
  upload: {
    max_file_size: 500, // MB
    allowed_formats: ['mp4', 'mov', 'webm', 'avi'],
    allowed_mime_types: ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo']
  }
}
```

### DOM Integration

- Button is injected into `.fcom_media_actions` container
- Inserted after the 2nd button (after image upload button)
- Dialog is teleported to `body` element

### Shortcode System

- Pattern: `[fchub_stream:VIDEO_ID]`
- Replaced with responsive iframe HTML
- Processed on post save and on display
- Supports both Cloudflare Stream and Bunny.net Stream

## API Endpoints

### Upload Video

```
POST /wp-json/fluent-community/v2/stream/video-upload
Content-Type: multipart/form-data
X-WP-Nonce: {nonce}

Body: FormData { file: File }

Response: {
  success: true,
  data: {
    video_id: string,
    provider: string,
    status: string,
    thumbnail_url: string,
    html: string,
    width: number,
    height: number
  }
}
```

### Check Status

```
GET /wp-json/fluent-community/v2/stream/video-status/{video_id}?provider={provider}
X-WP-Nonce: {nonce}

Response: {
  success: true,
  data: {
    video_id: string,
    provider: string,
    status: string,
    readyToStream: boolean,
    html: string,
    thumbnail_url: string
  }
}
```

## Accessibility

- ARIA labels on all interactive elements
- Keyboard navigation support
- Focus states on buttons
- Screen reader compatible
- Semantic HTML structure
- Alt text on images

## Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Vue 3 requires ES2015+ support
- IE11 not supported (graceful degradation)

## Performance

- Bundle size: ~125KB (~49KB gzipped)
- Optimized re-renders (reactive state)
- Minimal DOM manipulation
- Debounced status checks
- AbortController for cancellation

## Code Quality

- Vue 3 Composition API
- Shared reactive state pattern
- Event-based communication
- Proper cleanup (onUnmounted)
- Comprehensive error handling
- JSDoc comments
- Consistent naming conventions

## License

Same as parent plugin (FCHub Stream)
