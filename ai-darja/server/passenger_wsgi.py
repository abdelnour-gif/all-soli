"""تشغيل البوت على استضافة مشتركة (cPanel ← Setup Python App، مثل Namecheap).

cPanel يستعمل Passenger اللي يفهم WSGI برك، والبوت مكتوب بـ FastAPI (ASGI)،
لهذا نستعملو a2wsgi باش نربطو بيناتهم.

ملاحظة: على الاستضافة المشتركة استعمل LLM_PROVIDER=rules (المحرك المدمج).
النموذج (Ollama) يحتاج VPS.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from a2wsgi import ASGIMiddleware  # noqa: E402

from darjabot.app import _startup, app  # noqa: E402

# Passenger ما يشغّلش أحداث البداية تاع FastAPI، نشغلوها بيدنا
_startup()

application = ASGIMiddleware(app)
