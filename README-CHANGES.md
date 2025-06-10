# Bunny Media Offload - Optimization Fixes

This document explains the changes made to fix the timeout issues with the BMO (Bunny Media Optimization) API integration.

## Problem Identified

The original implementation encountered consistent timeout errors:
- `cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received`
- All images failing in batches of 20 images

## Root Causes

1. **Batch Size Too Large**: Processing 20 images at once was causing timeouts
2. **API Timeout Too Short**: 30-second timeout was insufficient for large image processing
3. **No Retry Logic**: Failed requests were not retried
4. **Inefficient Error Handling**: Timeouts were treated as fatal errors

## Changes Implemented

### 1. API Request Configuration (`includes/class-bunny-bmo-api.php`)

- Increased API timeout from 30 seconds to 120 seconds
- Added explicit HTTP version (1.1) and proper headers
- Added `userThresholdKb` parameter with a default value of 150KB
- Improved HTTP request parameters for better stability

### 2. Batch Size Reduction (`includes/class-bunny-bmo-processor.php`)

- Reduced maximum batch size from 5 images to 3 images per request
- This prevents timeouts by processing fewer images in each API call

### 3. Retry Logic (`includes/class-bunny-optimization-controller.php`)

- Implemented retry mechanism with up to 2 retries for failed requests
- Added delay between retries to prevent overwhelming the API
- Better error reporting for failed batches
- Reduced client-side batch size from 20 to 3

### 4. Frontend JavaScript Improvements (`assets/js/bunny-optimization.js`)

- Increased AJAX timeout from 60 seconds to 180 seconds
- Added special handling for timeout errors to provide a better user experience
- Improved error reporting and logging
- Allow continuation to next batch even after a timeout error

## Expected Results

These changes should solve the timeout issues by:

1. Processing fewer images per batch (3 instead of 20)
2. Giving the API more time to process requests (120 seconds instead of 30)
3. Implementing retry logic to handle transient failures
4. Providing better user feedback about processing status

## Additional Recommendations

1. **Monitor API Performance**: Keep an eye on API response times and adjust batch size if needed
2. **Consider Async Processing**: For very large images, consider implementing an asynchronous processing queue
3. **Optimize Before Upload**: When possible, optimize images before uploading to reduce processing time

## Testing

After implementing these changes, test the optimization feature with:
1. Small batches of images (3-5 images)
2. Various image sizes (small, medium, and large files)
3. Different image formats (JPEG, PNG, etc.)

## Troubleshooting

If timeouts persist:
1. Further reduce batch size to 1 or 2 images
2. Check network connectivity to the BMO API endpoints
3. Verify that the API key and region settings are correct
4. Review server PHP configuration for timeout limits 