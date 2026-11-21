#!/usr/bin/env python3
"""يبني ملف train_colab.ipynb (شغّله مرة أخرى إذا عدّلت الخلايا)."""
import json

cells = []


def md(text):
    cells.append({"cell_type": "markdown", "metadata": {}, "source": text.strip()})


def code(text):
    cells.append({"cell_type": "code", "metadata": {}, "execution_count": None, "outputs": [], "source": text.strip()})


md("""
# 🇩🇿 تدريب نموذج خدمة الزبائن بالدارجة الجزائرية

**قبل البدء:** من القائمة اختر `Runtime` ← `Change runtime type` ← **T4 GPU** ثم `Save`.

بعدها شغّل الخلايا واحدة بواحدة بالضغط على ▶️ (أو `Shift + Enter`).
""")

md("## 1) تثبيت المكتبات (2 إلى 4 دقائق)")
code("""
!pip install -q unsloth
!nvidia-smi --query-gpu=name,memory.total --format=csv
""")

md("""
## 2) رفع ملفات البيانات
ارفع `train.jsonl` و `test.jsonl` (موجودين في مجلد `ai-darja/data/` في المستودع).
""")
code("""
import os
from google.colab import files
if not (os.path.exists("train.jsonl") and os.path.exists("test.jsonl")):
    files.upload()
print("train:", sum(1 for _ in open("train.jsonl")), "| test:", sum(1 for _ in open("test.jsonl")))
""")

md("""
## 3) تحميل النموذج الأساسي
`Qwen2.5-3B-Instruct` نموذج صغير وقوي في العربية. إذا حبيت نتيجة أحسن (وأبطأ) بدّلو بـ `unsloth/Qwen2.5-7B-Instruct-bnb-4bit`.
""")
code("""
from unsloth import FastLanguageModel
import torch

BASE_MODEL = "unsloth/Qwen2.5-3B-Instruct-bnb-4bit"
MAX_LEN = 2048

model, tokenizer = FastLanguageModel.from_pretrained(
    model_name=BASE_MODEL, max_seq_length=MAX_LEN, load_in_4bit=True,
)

model = FastLanguageModel.get_peft_model(
    model,
    r=16, lora_alpha=16, lora_dropout=0, bias="none",
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    use_gradient_checkpointing="unsloth", random_state=42,
)
""")

md("## 4) تجهيز البيانات")
code("""
from datasets import load_dataset
from unsloth.chat_templates import get_chat_template

tokenizer = get_chat_template(tokenizer, chat_template="qwen-2.5")

def to_text(batch):
    return {"text": [tokenizer.apply_chat_template(m, tokenize=False, add_generation_prompt=False)
                     for m in batch["messages"]]}

train_ds = load_dataset("json", data_files="train.jsonl", split="train").map(to_text, batched=True)
test_ds = load_dataset("json", data_files="test.jsonl", split="train")
print(train_ds[0]["text"][-400:])
""")

md("## 5) قبل التدريب: نشوفو كيفاش يجاوب النموذج الأصلي (للمقارنة)")
code("""
def ask(messages, max_new_tokens=150):
    FastLanguageModel.for_inference(model)
    inputs = tokenizer.apply_chat_template(messages, add_generation_prompt=True, return_tensors="pt").to("cuda")
    out = model.generate(input_ids=inputs, max_new_tokens=max_new_tokens, temperature=0.3, do_sample=True)
    return tokenizer.decode(out[0][inputs.shape[1]:], skip_special_tokens=True)

for ex in test_ds.select(range(3)):
    msgs = ex["messages"]
    print("👤", msgs[1]["content"])
    print("🤖", ask(msgs[:2]))
    print("-" * 60)
""")

md("""
## 6) التدريب 🚀 (تقريباً 20 إلى 40 دقيقة على T4)
النموذج يتعلم من ردود المساعد فقط (ماشي من أسئلة الزبون).
""")
code("""
import inspect
from trl import SFTTrainer, SFTConfig
from unsloth.chat_templates import train_on_responses_only

FastLanguageModel.for_training(model)

# أسماء الإعدادات تتبدل بين إصدارات مكتبة TRL، نختارو اللي كاين
cfg = dict(
    dataset_text_field="text",
    max_seq_length=MAX_LEN, max_length=MAX_LEN,
    per_device_train_batch_size=2,
    gradient_accumulation_steps=4,
    num_train_epochs=1,
    learning_rate=2e-4,
    warmup_steps=10,
    logging_steps=10,
    optim="adamw_8bit",
    weight_decay=0.01,
    lr_scheduler_type="linear",
    seed=42,
    output_dir="outputs",
    report_to="none",
)
accepted = inspect.signature(SFTConfig.__init__).parameters
args = SFTConfig(**{k: v for k, v in cfg.items() if k in accepted})

try:
    trainer = SFTTrainer(model=model, processing_class=tokenizer, train_dataset=train_ds, args=args)
except TypeError:
    trainer = SFTTrainer(model=model, tokenizer=tokenizer, train_dataset=train_ds, args=args)

trainer = train_on_responses_only(
    trainer,
    instruction_part="<|im_start|>user\\n",
    response_part="<|im_start|>assistant\\n",
)
stats = trainer.train()
print(stats)
""")

md("## 7) بعد التدريب: التجربة")
code("""
for ex in test_ds.select(range(8)):
    msgs = ex["messages"]
    print("👤", msgs[1]["content"])
    print("🤖", ask(msgs[:2]))
    print("✅ المتوقع:", msgs[2]["content"])
    print("-" * 60)
""")

md("""
### جرّب بمحل جديد خاص بيك
بدّل المعلومات تحت ببيانات محلك، واكتب أي سؤال.
""")
code('''
MY_SHOP = """اسم المحل: محل الأمل للأحذية
المدينة: عنابة
العنوان: وسط المدينة، قريب من الكورنيش
أوقات العمل: من 9 الصباح حتى 8 الليل
الهاتف: 0555000000
التوصيل: متوفر، السعر 500 دج، المدة 48 ساعة، المناطق: 58 ولاية
الدفع: الدفع عند الاستلام
الترجيع/الضمان: التبديل في 3 أيام
المنتجات/الخدمات:
- سباط رياضي: 4500 دج | من 39 حتى 45 | متوفر
- صندالة: 2200 دج | من 36 حتى 41 | غير متوفر حالياً"""

SYSTEM = ("أنت مساعد خدمة الزبائن لمحل \\"محل الأمل للأحذية\\". تهدر بالدارجة الجزائرية بطريقة مؤدبة وقصيرة وواضحة. "
          "جاوب غير من المعلومات اللي تحت. إذا المعلومة ما كانتش، قول للزبون بلي راح تسقسي وترجعلو، "
          "ولا عطيه رقم الهاتف. ما تخترعش أسعار ولا معلومات.\\n\\nمعلومات المحل:\\n" + MY_SHOP)

question = "السلام عليكم، بشحال السباط الرياضي وكاين 43؟"
print(ask([{"role": "system", "content": SYSTEM}, {"role": "user", "content": question}]))
''')

md("""
## 8) الحفظ والتحميل
- **LoRA**: ملف صغير (حوالي 100 ميغا) فيه التعديلات تاعك.
- **GGUF**: النموذج كامل جاهز للتشغيل على حاسوبك بـ Ollama (حوالي 2 جيغا). التصدير ياخذ 5 إلى 10 دقائق.
""")
code("""
model.save_pretrained("darja_lora")
tokenizer.save_pretrained("darja_lora")

model.save_pretrained_gguf("darja_gguf", tokenizer, quantization_method="q4_k_m")
!ls -lh darja_gguf
""")
md("""
### تحميل الملف
الملف كبير (حوالي 2 جيغا)، لهذا ننسخوه لـ **Google Drive** باسم `darja.gguf` (أضمن من التحميل المباشر).
من بعد حمّلو من drive.google.com، وحطو في مجلد `ai-darja` جنب `Modelfile`.
""")
code("""
import glob, os, shutil
from google.colab import drive

# Unsloth يحفظ الملف في مجلد ينتهي بـ _gguf (مثلاً darja_gguf_gguf)
gguf = [f for f in glob.glob("/content/**/*.gguf", recursive=True) if "Q4_K_M" in f or "q4_k_m" in f]
gguf = gguf or glob.glob("/content/**/*.gguf", recursive=True)
assert gguf, "ما لقيتش ملف gguf: شوف إذا الخلية اللي قبل كملت بلا أخطاء"
src = max(gguf, key=os.path.getsize)
print("الملف:", src, round(os.path.getsize(src) / 1e9, 2), "GB")

drive.mount("/content/drive")
shutil.copy(src, "/content/drive/MyDrive/darja.gguf")
shutil.make_archive("/content/drive/MyDrive/darja_lora", "zip", "darja_lora")
print("✅ تنسخ لـ Google Drive: darja.gguf و darja_lora.zip")
""")

md("""
### (اختياري) الرفع لـ Hugging Face
أنشئ Token من huggingface.co/settings/tokens (نوع Write)، وبدّل `USERNAME`.
""")
code("""
# from huggingface_hub import login; login()
# model.push_to_hub_gguf("USERNAME/darja-customer-service", tokenizer, quantization_method="q4_k_m")
""")

nb = {
    "cells": cells,
    "metadata": {
        "accelerator": "GPU",
        "colab": {"provenance": [], "gpuType": "T4"},
        "kernelspec": {"display_name": "Python 3", "name": "python3"},
        "language_info": {"name": "python"},
    },
    "nbformat": 4,
    "nbformat_minor": 0,
}
with open("train_colab.ipynb", "w", encoding="utf-8") as f:
    json.dump(nb, f, ensure_ascii=False, indent=1)
print("✅ train_colab.ipynb")
