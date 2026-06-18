"""
OCR Utilities — Image preprocessing functions for improved OCR accuracy.

Provides deskewing, binarization, noise removal, and contrast enhancement
to maximize text recognition quality on scanned and handwritten pages.
"""

import logging
from typing import Optional

logger = logging.getLogger(__name__)


def preprocess_for_ocr(image, target_dpi: int = 300):
    """
    Apply standard preprocessing pipeline to an image for OCR.

    Steps:
        1. Convert to grayscale
        2. Enhance contrast
        3. Binarize (adaptive threshold)
        4. Remove noise (median filter)

    Args:
        image: PIL.Image.Image object.
        target_dpi: Target DPI (informational, image should already be at this DPI).

    Returns:
        Preprocessed PIL.Image.Image ready for OCR.
    """
    from PIL import Image, ImageFilter, ImageOps

    # Step 1: Convert to grayscale
    if image.mode != 'L':
        image = image.convert('L')

    # Step 2: Enhance contrast using auto-contrast
    image = ImageOps.autocontrast(image, cutoff=2)

    # Step 3: Apply slight sharpening
    image = image.filter(ImageFilter.SHARPEN)

    # Step 4: Light noise removal with median filter
    image = image.filter(ImageFilter.MedianFilter(size=3))

    return image


def binarize_image(image, threshold: int = 128):
    """
    Convert image to pure black and white using a threshold.

    Args:
        image: PIL.Image.Image (should be grayscale).
        threshold: Pixel value threshold (0-255). Below = black, above = white.

    Returns:
        Binary PIL.Image.Image.
    """
    from PIL import Image

    if image.mode != 'L':
        image = image.convert('L')

    return image.point(lambda x: 255 if x > threshold else 0, '1')


def deskew_image(image, max_angle: float = 10.0):
    """
    Detect and correct slight rotation (skew) in scanned pages.

    Uses a simple projection profile method to detect text line angle.

    Args:
        image: PIL.Image.Image object.
        max_angle: Maximum skew angle to correct (degrees).

    Returns:
        Deskewed PIL.Image.Image.
    """
    try:
        import numpy as np
        from PIL import Image

        if image.mode != 'L':
            img_gray = image.convert('L')
        else:
            img_gray = image

        img_array = np.array(img_gray)

        # Simple heuristic: try small rotations and find the one
        # that maximizes the variance of horizontal projection
        best_angle = 0
        best_variance = 0

        for angle in np.arange(-max_angle, max_angle + 0.5, 0.5):
            rotated = image.rotate(angle, expand=False, fillcolor=255)
            rotated_array = np.array(rotated.convert('L'))

            # Horizontal projection profile
            projection = np.sum(rotated_array < 128, axis=1)
            variance = np.var(projection)

            if variance > best_variance:
                best_variance = variance
                best_angle = angle

        if abs(best_angle) > 0.5:
            logger.info(f"  Deskew: corrected {best_angle:.1f}° rotation")
            return image.rotate(best_angle, expand=True, fillcolor=255)

        return image

    except ImportError:
        logger.warning("numpy not available, skipping deskew")
        return image


def remove_noise(image, kernel_size: int = 3):
    """
    Remove noise from an image using median filtering.

    Args:
        image: PIL.Image.Image object.
        kernel_size: Size of the median filter kernel (must be odd).

    Returns:
        Denoised PIL.Image.Image.
    """
    from PIL import ImageFilter

    return image.filter(ImageFilter.MedianFilter(size=kernel_size))


def enhance_contrast(image, clip_limit: float = 2.0):
    """
    Enhance contrast of an image for better OCR on low-contrast scans.

    Uses PIL's auto-contrast with configurable cutoff.

    Args:
        image: PIL.Image.Image object.
        clip_limit: Percentage of pixels to clip at each end.

    Returns:
        Contrast-enhanced PIL.Image.Image.
    """
    from PIL import ImageOps

    if image.mode != 'L':
        image = image.convert('L')

    return ImageOps.autocontrast(image, cutoff=clip_limit)


def detect_text_regions(image, min_area: int = 500):
    """
    Detect regions of an image that likely contain text.

    Uses simple connected component analysis on binarized image.

    Args:
        image: PIL.Image.Image object.
        min_area: Minimum pixel area for a region to be considered text.

    Returns:
        List of bounding boxes (x, y, width, height) for text regions.
    """
    try:
        import numpy as np
        from PIL import Image

        if image.mode != 'L':
            img_gray = image.convert('L')
        else:
            img_gray = image

        # Binarize
        img_array = np.array(img_gray)
        binary = (img_array < 128).astype(np.uint8)

        # Simple row/column projection to find text blocks
        regions = []

        # Horizontal projection to find text rows
        h_projection = np.sum(binary, axis=1)
        in_text = False
        row_start = 0

        for i, val in enumerate(h_projection):
            if val > 10 and not in_text:
                in_text = True
                row_start = i
            elif val <= 10 and in_text:
                in_text = False
                if i - row_start > 10:  # Minimum height
                    # Find column bounds within this row region
                    row_slice = binary[row_start:i, :]
                    v_projection = np.sum(row_slice, axis=0)

                    col_indices = np.where(v_projection > 0)[0]
                    if len(col_indices) > 0:
                        col_start = int(col_indices[0])
                        col_end = int(col_indices[-1])
                        width = col_end - col_start
                        height = i - row_start

                        if width * height >= min_area:
                            regions.append((col_start, row_start, width, height))

        return regions

    except ImportError:
        logger.warning("numpy not available, cannot detect text regions")
        return []
