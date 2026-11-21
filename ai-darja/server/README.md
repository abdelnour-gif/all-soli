# 🇩🇿 Darja Bot: نظام خدمة الزبائن بالدارجة الجزائرية

نظام كامل يرد على زبائن المحلات بالدارجة 24/24: يجاوب على الأسئلة، ياخذ الطلبيات والحجوزات،
ينبّه صاحب المحل، ويحوّل للبشري كي يلزم. يخدم على **الموقع، تيليغرام، واتساب، ماسنجر وإنستغرام**.

## ✨ واش يدير

| الميزة | التفاصيل |
|---|---|
| 💬 يفهم الدارجة | "بشحال"، "كاين"، "نكوموندي"، "livraison"... مع الأخطاء والكتابة بالفرنسية |
| 🛒 ياخذ الطلبيات | المنتج ← المقاس ← الاسم ← الهاتف ← الولاية والعنوان ← ملخص ← تأكيد |
| 📅 يحجز المواعيد | للعيادات والصالونات: الخدمة ← الاسم ← الهاتف ← الوقت |
| 🧠 يتفكر السياق | "بشحال الجاكيطة؟" ومن بعد "نحب نطلبها L" ← يفهم بلي يقصد الجاكيطة |
| 🙋 يحوّل للبشري | "نحب نهدر مع المسؤول" ← البوت يحبس ويتنبّه صاحب المحل |
| ⚠️ يكشف الشكاوى | "الطلبية ما وصلتش" ← يعتذر وينبّه صاحب المحل |
| 🛡️ ما يخترعش | حارس يرفض أي سعر ولا رقم هاتف ماهوش في معلومات المحل |
| 🔔 تنبيهات | كل طلبية جديدة ولا زبون يحتاج بشري ← رسالة تيليغرام لصاحب المحل |
| 📊 لوحة تحكم | المحادثات، الطلبيات (تصدير Excel)، السلع، القنوات، تجربة البوت |
| 🎓 يتحسّن | صحّح ردود البوت ← صدّر ← عاود درّب النموذج على محادثات حقيقية |
| 🏪 محلات بزاف | كل محل عندو معلوماتو وقنواتو، وصاحب المحل عندو رابط خاص يشوف محلو برك |

## 🧠 كيفاش يخدم (المحرك)

```
رسالة الزبون
   │
   ├─ البوت مطفي ولا المحادثة عند بشري؟ ← يسجّل برك
   ├─ طلبية مفتوحة؟ ← مدير الطلبيات (مضمون 100%، بلا نموذج)
   ├─ طلب بشري / شكوى / بداية طلبية؟ ← رد مضمون + تنبيه
   └─ سؤال عادي ← النموذج (إذا كاين) ← الحارس يتأكد
                    └─ إذا طاح ولا اخترع ← المحرك المدمج يجاوب
```

**المحركات المتوفرة** (`LLM_PROVIDER` في `.env`):

| القيمة | الشرح | التكلفة |
|---|---|---|
| `rules` | المحرك المدمج: يفهم الدارجة ويجاوب من معلومات المحل. يخدم من أول دقيقة | مجاني |
| `ollama` | **نموذجك المدرَّب** (ملف GGUF من Colab) | مجاني (حاسوبك/سيرفرك) |
| `openai` | أي سيرفر نموذج متوافق (vLLM، LM Studio، llama.cpp) | حسب السيرفر |
| `anthropic` | Claude (يحتاج `ANTHROPIC_API_KEY`) | حسب الاستعمال |

> في كل الحالات، الطلبيات والحجوزات يسيّرهم المحرك المدمج، باش ما يكون حتى خطأ في الأسعار ولا المعلومات.

## 🚀 التشغيل السريع (على حاسوبك)

تحتاج Python 3.10 ولا أحدث.

```bash
cd ai-darja/server
```
```bash
pip install -r requirements.txt
```
```bash
uvicorn darjabot.app:app --port 8000
```

افتح **http://localhost:8000/admin/**. كلمة السر تلقاها في `data/admin_password.txt`.
تتزاد 3 محلات تجريبية (بوتيك ملابس، بيتزيريا، عيادة) باش تجرب دغيا.

جرّب في الترمينال بلا متصفح:
```bash
python -m darjabot.cli boutique-leila
```

## 🐳 التشغيل بـ Docker (للسيرفر)

```bash
cp .env.example .env
```
```bash
docker compose up -d
```

مع نموذجك المدرَّب على Ollama:
```bash
docker compose --profile ollama up -d
```
```bash
docker compose exec ollama ollama create darja -f /workspace/Modelfile
```
وحط `LLM_PROVIDER=ollama` في `.env` ثم:
```bash
docker compose up -d bot
```

### 🔒 https أوتوماتيكي (لازم لتيليغرام وواتساب)
1. وجّه الدومين تاعك (مثلاً `bot.monsite.dz`) للـ IP تاع السيرفر.
2. في `.env`: `DOMAIN=bot.monsite.dz` و `PUBLIC_BASE_URL=https://bot.monsite.dz`
3. شغّل:
```bash
docker compose --profile https up -d
```
Caddy يجيب شهادة مجانية وحدو. لوحة التحكم: `https://bot.monsite.dz/admin/`

> 💡 **السيرفر:** أي VPS بـ 2 جيغا رام يكفي للمحرك المدمج. لنموذج 3B على Ollama بلا GPU: 4 حتى 8 جيغا رام
> (الرد ياخذ 3 حتى 10 ثواني). بلا Docker، تقدر تستعمل Nginx قدام `uvicorn`.
>
> 🔐 كلمة سر المدير: `docker compose exec bot cat /data/admin_password.txt`

## 🌐 التشغيل على استضافة مشتركة (cPanel / Namecheap)

يخدم **بالمحرك المدمج برك** (النموذج يحتاج VPS). مليح للتجربة مع أول محلات.

1. **cPanel ← Subdomains:** أنشئ `bot.دومينك.com`.
2. **cPanel ← Setup Python App ← Create Application:**
   - Python version: أعلى نسخة كاينة (3.10 ولا أحدث)
   - Application root: `darjabot`
   - Application URL: `bot.دومينك.com`
   - Application startup file: `passenger_wsgi.py`
   - Application Entry point: `application`
3. **File Manager:** ارفع محتوى مجلد `server` (`darjabot/`، `passenger_wsgi.py`، `requirements-shared.txt`) لـ `/home/USER/darjabot`.
4. **في صفحة التطبيق:** Configuration files ← زيد `requirements-shared.txt` ← **Run Pip Install**.
5. **Environment variables:** `ADMIN_PASSWORD`، `PUBLIC_BASE_URL=https://bot.دومينك.com`، `LLM_PROVIDER=rules`
6. **Restart**، وفعّل SSL من **SSL/TLS Status ← Run AutoSSL**.
7. افتح `https://bot.دومينك.com/admin/`

> تيليغرام على الاستضافة المشتركة: استعمل زر **"ربط البوت"** (webhook)، ماشي `telegram_polling.py`.

## 🔌 ربط القنوات

كلشي يتدار من **لوحة التحكم ← القنوات**.

### 🌐 الموقع
- **رابط الدردشة:** `https://سيرفرك/c/اسم-المحل`، حطو في البيو تاع إنستغرام/فيسبوك.
- **الويدجت:** زر دردشة يطلع في أي موقع:
  ```html
  <script src="https://سيرفرك/widget.js" data-shop="اسم-المحل" defer></script>
  ```

### ✈️ تيليغرام (الأسهل)
1. في تيليغرام: **@BotFather** ← `/newbot` ← انسخ التوكن.
2. حطو في لوحة التحكم ← حفظ ← **ربط البوت**.
3. **ما عندكش https؟** شغّل البوت بلا webhook:
   ```bash
   python scripts/telegram_polling.py
   ```
4. **التنبيهات:** ابعث `/myid` للبوت، وحط الرقم في "رقم محادثة صاحب المحل".

### 🟢 واتساب (WhatsApp Cloud API)
1. **developers.facebook.com** ← تطبيق جديد ← زيد **WhatsApp**.
2. Webhook: الرابط `https://سيرفرك/webhook/meta`، والـ Verify token تلقاه في لوحة التحكم. اشترك في **messages**.
3. حط **Phone number ID** و**Access token** في لوحة التحكم.
4. حط `META_APP_SECRET` في `.env` (للأمان).

### 💬 ماسنجر و 📸 إنستغرام
نفس تطبيق Meta، نفس الـ webhook. حط **Page ID** و**Page access token**.

## 🏪 بيع الخدمة لأصحاب المحلات

1. **لوحة التحكم ← المحل والسلع ← محل جديد**: الاسم، النشاط، المعلومات، السلع.
2. زيد "أسماء أخرى" للسلع باش البوت يفهم الزبائن (مثلاً: جاكيطة، veste، blouson).
3. **القنوات**: انسخ **رابط لوحة التحكم الخاص** وابعثو لصاحب المحل. يشوف محادثاتو وطلبياتو برك.
4. صاحب المحل يقدر يجاوب بيدو من اللوحة، والبوت يحبس في هاديك المحادثة (ويرجع وحدو بعد 6 ساعات).

## 🎓 تحسين النموذج بالمحادثات الحقيقية

```
البوت يخدم ← صحّح الردود الغالطة (✏️) ← لوحة التحكم ← التدريب ← تحميل
   ← حطو في ai-darja/data/my_examples.jsonl ← python3 generate_data.py
   ← عاود التدريب في Colab ← ollama create darja ← نموذج أحسن 🎯
```

## 🔒 الأمان

- كلمة سر المدير + جلسات موقّعة (HMAC)، وحد لمحاولات الدخول الغالطة.
- صاحب المحل يشوف محلو برك (رابط بمفتاح سري، تقدر تبدلو).
- التوكنات ما ترجعش أبداً للمتصفح بعد الحفظ.
- Webhooks محمية: سر تيليغرام + توقيع Meta (`META_APP_SECRET`).
- حد للرسائل في الدردشة العامة (`PUBLIC_RATE_LIMIT`).

## 🧪 الاختبارات

```bash
pip install -r requirements-dev.txt
```
```bash
python -m pytest -q
```

## 📁 الملفات

| الملف | الدور |
|---|---|
| `darjabot/engine.py` | المحرك الرئيسي (يقرر كيفاش يجاوب) |
| `darjabot/rules.py` | المحرك المدمج بالدارجة |
| `darjabot/orders.py` | الطلبيات والحجوزات |
| `darjabot/extract.py` | استخراج: المنتج، الهاتف، الولاية، الاسم، المقاس |
| `darjabot/guard.py` | الحارس ضد المعلومات المخترعة |
| `darjabot/providers.py` | Ollama / OpenAI-compatible / Claude |
| `darjabot/prompt.py` | بطاقة المحل (نفس صيغة التدريب) |
| `darjabot/app.py` | السيرفر، لوحة التحكم، الـ webhooks |
| `darjabot/static/` | لوحة التحكم، صفحة الدردشة، الويدجت |
| `scripts/telegram_polling.py` | تيليغرام بلا https |
