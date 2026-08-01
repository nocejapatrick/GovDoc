"""
HTTP API for the OCR service.

Kept for direct testing and debugging:
    curl -F "file=@some.pdf" http://localhost:8080/extract

Production traffic flows through the SQS worker (worker.py) instead.
"""

import pytesseract
from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi import Form

from extractor import PermanentExtractionError, extract_pdf

app = FastAPI(title="OCR Service", version="2.0.0")


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "tesseract_version": str(pytesseract.get_tesseract_version()),
    }


@app.post("/extract")
async def extract(file: UploadFile = File(...)) -> dict:
    if file.content_type not in ("application/pdf", "application/octet-stream"):
        raise HTTPException(status_code=415, detail="Only PDF files are accepted.")

    payload = await file.read()

    try:
        return extract_pdf(payload, filename=file.filename or "unknown.pdf")
    except PermanentExtractionError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.post("/sign")
async def sign_pdf(
    pdf: UploadFile = File(...),
    signature: UploadFile = File(...),
    page: int = Form(...),
    x: float = Form(...),
    y: float = Form(...),
    width: float = Form(...),
    height: float = Form(...),
    render_scale: float = Form(...),
):
    pdf_bytes = await pdf.read()
    sig_bytes = await signature.read()

    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    target_page = doc[page - 1]

    # Convert screen/canvas pixel coords back to real PDF points
    pdf_x = x / render_scale
    pdf_y = y / render_scale
    pdf_w = width / render_scale
    pdf_h = height / render_scale

    rect = fitz.Rect(pdf_x, pdf_y, pdf_x + pdf_w, pdf_y + pdf_h)
    target_page.insert_image(rect, stream=sig_bytes)

    output = doc.tobytes()
    doc.close()

    return Response(content=output, media_type="application/pdf")