# BMO Optimization Refactoring

This document explains the significant changes made to the BMO (Bunny Media Optimization) API integration to solve persistent timeout issues.

## Problem

The original implementation was encountering consistent timeout errors:
- `cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received`
- `AJAX Error: timeout - timeout`
- All images were failing in batches of 20 images

After examining both client and server logs, we discovered that while the API was processing images successfully, the client was timing out waiting for a response from large batches.

## Solution: Image-by-Image Processing

We completely refactored the optimization process to use an image-by-image approach instead of batch processing:

1. **One Image at a Time**: Process each image individually, which ensures more reliable processing
2. **Separate AJAX Requests**: Each image gets its own AJAX request with a shorter timeout
3. **Progressive Loading**: Load small batches of image IDs (10 at a time) and process them sequentially
4. **Robust Retry Logic**: Each image can be retried independently if it fails

## Key Changes

### 1. JavaScript Refactoring (`assets/js/bunny-optimization.js`)

- Completely rewrote the optimization logic to process one image at a time
- Added a queue system that loads images in small batches but processes them individually
- Implemented per-image retry logic with configurable retry attempts
- Improved error handling and progress reporting
- Better timeout handling and recovery

### 2. Server-Side Processing (`includes/class-bunny-optimization-controller.php`)

- Added new AJAX endpoints:
  - `bunny_get_images_to_optimize`: Gets a small batch of image IDs to process
  - `bunny_optimize_single_image`: Processes a single image
- Implemented single image processing logic with robust error handling
- Maintained session tracking for overall progress

### 3. API Communication (`includes/class-bunny-bmo-api.php`)

- Optimized the single image processing method for more reliable API calls
- Reduced timeout for individual images to 45 seconds (sufficient for single images)
- Improved error handling and logging

### 4. Session Management (`includes/class-bunny-optimization-session.php`)

- Added helper method to get session status
- Ensured proper tracking of individual image processing results

## Benefits

1. **Reliability**: If one image fails, others can still be processed
2. **Resilience**: Each image has its own retry logic
3. **Better UX**: More accurate progress reporting
4. **Reduced Server Load**: Smaller, more frequent requests instead of large batches
5. **Detailed Error Reporting**: Per-image error messages

## Usage

The user interface remains the same, but the underlying processing is completely different:

1. Click the "Optimize Images" button
2. System creates a session and retrieves the first batch of image IDs
3. Each image is processed individually with its own AJAX request
4. Progress is updated in real time as each image completes
5. Additional image IDs are loaded as needed until all images are processed

## Configuration Options

The optimization module has these configurable options:

- `processingDelay`: Delay between processing images (default: 1000ms)
- `maxRetries`: Maximum retries per image (default: 3)
- `retryDelay`: Delay between retries (default: 3000ms)

## Implementation Details

### New JavaScript Methods

- `processImagesOneByOne()`: Main entry point for the one-by-one processing
- `processNextImage()`: Processes the next image in the queue
- `processSingleImage()`: Makes AJAX request to process a single image
- `getImagesToProcess()`: Gets a batch of image IDs from the server

### New PHP Methods

- `ajax_get_images_to_optimize()`: AJAX handler to get images that need optimization
- `ajax_optimize_single_image()`: AJAX handler to process a single image
- `process_single_image()`: Core logic for single image processing

## Monitoring and Debugging

The system includes improved logging:

- Server-side logs track each image's processing status
- Client-side console logging shows detailed progress
- UI displays current progress and any errors

## Fallback Strategy

If an image fails after all retry attempts:
1. It's marked as failed in the session
2. Processing continues with the next image
3. At the end, a summary shows successful and failed images

This approach ensures maximum throughput even with problematic images. 