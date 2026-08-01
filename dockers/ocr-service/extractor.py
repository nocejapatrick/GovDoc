"""
Core PDF extraction logic, shared by the HTTP API (main.py)
and the SQS worker (worker.py).

Per page:
  - Native text  -> extracted directly with PyMuPDF
  - Scanned page -> rendered at 300 DPI and run through Tesseract
"""

import io
import time

import fitz  # PyMuPDF
import pytesseract
from PIL import Image

MIN_CHARS_FOR_TEXT_PAGE = 50
OCR_DPI = 300
OCR_LANGUAGES = "eng"  # e.g. "eng+fil" if you add Filipino traineddata
MAX_PAGES = 500


class PermanentExtractionError(Exception):
    """The PDF can never be processed (corrupt, encrypted, too large).

    The worker should report failure and NOT retry.
    """


def extract_pdf(payload: bytes, filename: str = "unknown.pdf", on_progress=None) -> dict:
    started = time.monotonic()

    try:
        doc = fitz.open(stream=payload, filetype="pdf")
    except Exception as exc:
        raise PermanentExtractionError(f"Could not open PDF: {exc}") from exc

    if doc.is_encrypted and not doc.authenticate(""):
        raise PermanentExtractionError("PDF is password-protected.")

    if doc.page_count > MAX_PAGES:
        raise PermanentExtractionError(
            f"PDF has {doc.page_count} pages; the limit is {MAX_PAGES}."
        )

    pages = []

    for index, page in enumerate(doc, start=1):
        native_text = page.get_text("text").strip()

        if len(native_text) >= MIN_CHARS_FOR_TEXT_PAGE:
            pages.append(
                {
                    "page": index,
                    "method": "text",
                    "char_count": len(native_text),
                    "text": native_text,
                }
            )
            continue

        ocr_text = _ocr_page(page)
        pages.append(
            {
                "page": index,
                "method": "ocr",
                "char_count": len(ocr_text),
                "text": ocr_text,
            }
        )
        if on_progress:
            on_progress(index, doc.page_count)

    doc.close()

    methods = {p["method"] for p in pages}
    overall = methods.pop() if len(methods) == 1 else "mixed"

    return {
        "filename": filename,
        "page_count": len(pages),
        "method": overall,
        "duration_seconds": round(time.monotonic() - started, 2),
        "text": "\n\n".join(p["text"] for p in pages if p["text"]),
        "pages": pages,
    }


def _ocr_page(page: fitz.Page) -> str:
    pixmap = page.get_pixmap(dpi=OCR_DPI, colorspace=fitz.csGRAY)
    image = Image.open(io.BytesIO(pixmap.tobytes("png")))
    return pytesseract.image_to_string(image, lang=OCR_LANGUAGES).strip()
