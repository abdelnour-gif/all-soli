"""الإعدادات: كلها تتقرا من متغيرات البيئة (أو من ملف .env)."""
from __future__ import annotations

import os
import secrets
from dataclasses import dataclass, field
from pathlib import Path


def _load_dotenv(path: Path) -> None:
    """قارئ .env بسيط بلا مكتبات خارجية. ما يبدلش المتغيرات الموجودة."""
    if not path.exists():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key, value = key.strip(), value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


def _env_bool(name: str, default: bool) -> bool:
    v = os.environ.get(name)
    if v is None:
        return default
    return v.strip().lower() in {"1", "true", "yes", "on"}


@dataclass
class Settings:
    data_dir: Path
    db_path: Path
    # LLM
    llm_provider: str            # rules | ollama | openai | anthropic
    ollama_url: str
    ollama_model: str
    openai_base_url: str
    openai_api_key: str
    openai_model: str
    anthropic_model: str
    anthropic_effort: str
    llm_timeout: float
    history_turns: int
    # الأمان
    admin_password: str
    secret_key: str
    public_rate_limit: int       # رسائل في الدقيقة لكل زائر
    # القنوات
    public_base_url: str
    wa_verify_token: str
    meta_app_secret: str
    notify_telegram_token: str
    extra: dict = field(default_factory=dict)


def _persisted_secret(data_dir: Path, name: str, nbytes: int = 32) -> str:
    """يولّد سر عشوائي مرة وحدة ويحفظو في data/ باش يبقى ثابت."""
    p = data_dir / name
    if p.exists():
        return p.read_text(encoding="utf-8").strip()
    value = secrets.token_urlsafe(nbytes)
    p.write_text(value, encoding="utf-8")
    try:
        p.chmod(0o600)
    except OSError:
        pass
    return value


def load_settings() -> Settings:
    base = Path(__file__).resolve().parent.parent
    _load_dotenv(base / ".env")

    data_dir = Path(os.environ.get("DATA_DIR", base / "data")).resolve()
    data_dir.mkdir(parents=True, exist_ok=True)

    admin_password = os.environ.get("ADMIN_PASSWORD") or _persisted_secret(data_dir, "admin_password.txt", 9)
    secret_key = os.environ.get("SECRET_KEY") or _persisted_secret(data_dir, "secret_key.txt")

    return Settings(
        data_dir=data_dir,
        db_path=Path(os.environ.get("DB_PATH", data_dir / "darjabot.sqlite3")),
        llm_provider=os.environ.get("LLM_PROVIDER", "rules").strip().lower(),
        ollama_url=os.environ.get("OLLAMA_URL", "http://localhost:11434").rstrip("/"),
        ollama_model=os.environ.get("OLLAMA_MODEL", "darja"),
        openai_base_url=os.environ.get("OPENAI_BASE_URL", "http://localhost:8001/v1").rstrip("/"),
        openai_api_key=os.environ.get("OPENAI_API_KEY", ""),
        openai_model=os.environ.get("OPENAI_MODEL", "darja"),
        anthropic_model=os.environ.get("ANTHROPIC_MODEL", "claude-opus-5-5"),
        anthropic_effort=os.environ.get("ANTHROPIC_EFFORT", "low"),
        llm_timeout=float(os.environ.get("LLM_TIMEOUT", "60")),
        history_turns=int(os.environ.get("HISTORY_TURNS", "10")),
        admin_password=admin_password,
        secret_key=secret_key,
        public_rate_limit=int(os.environ.get("PUBLIC_RATE_LIMIT", "20")),
        public_base_url=os.environ.get("PUBLIC_BASE_URL", "http://localhost:8000").rstrip("/"),
        wa_verify_token=os.environ.get("WA_VERIFY_TOKEN") or _persisted_secret(data_dir, "wa_verify_token.txt", 12),
        meta_app_secret=os.environ.get("META_APP_SECRET", ""),
        notify_telegram_token=os.environ.get("NOTIFY_TELEGRAM_TOKEN", ""),
        extra={"debug": _env_bool("DEBUG", False)},
    )


_settings: Settings | None = None


def get_settings() -> Settings:
    global _settings
    if _settings is None:
        _settings = load_settings()
    return _settings


def reset_settings() -> None:
    """للاختبارات: يعاود يقرا الإعدادات."""
    global _settings
    _settings = None
