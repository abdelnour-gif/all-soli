"""الولايات الجزائرية الـ58 مع الأسماء الشائعة (عربي/لاتيني/دارجة)."""
from __future__ import annotations

import re

from .normalize import normalize, to_latin_digits

# (الرقم، الاسم الرسمي، أسماء أخرى)
WILAYAS: list[tuple[int, str, tuple[str, ...]]] = [
    (1, "أدرار", ("adrar",)),
    (2, "الشلف", ("شلف", "chlef", "chlif")),
    (3, "الأغواط", ("لغواط", "laghouat")),
    (4, "أم البواقي", ("oum el bouaghi", "oeb")),
    (5, "باتنة", ("batna",)),
    (6, "بجاية", ("بجايه", "bejaia", "bgayet", "bougie")),
    (7, "بسكرة", ("biskra",)),
    (8, "بشار", ("bechar",)),
    (9, "البليدة", ("بليدة", "blida")),
    (10, "البويرة", ("بويرة", "bouira")),
    (11, "تمنراست", ("tamanrasset", "tam")),
    (12, "تبسة", ("tebessa",)),
    (13, "تلمسان", ("tlemcen",)),
    (14, "تيارت", ("tiaret",)),
    (15, "تيزي وزو", ("تيزي", "tizi ouzou", "tizi")),
    (16, "الجزائر العاصمة", ("العاصمة", "الجزاير", "دزاير", "الدزاير", "alger", "algiers", "dzayer")),
    (17, "الجلفة", ("جلفة", "djelfa")),
    (18, "جيجل", ("jijel",)),
    (19, "سطيف", ("setif", "stif")),
    (20, "سعيدة", ("saida",)),
    (21, "سكيكدة", ("skikda",)),
    (22, "سيدي بلعباس", ("بلعباس", "sidi bel abbes", "sba")),
    (23, "عنابة", ("annaba",)),
    (24, "قالمة", ("قالمه", "guelma")),
    (25, "قسنطينة", ("قسمطينة", "constantine", "qsentina")),
    (26, "المدية", ("مدية", "medea")),
    (27, "مستغانم", ("mostaganem", "mosta")),
    (28, "المسيلة", ("مسيلة", "msila", "m'sila")),
    (29, "معسكر", ("mascara",)),
    (30, "ورقلة", ("ouargla",)),
    (31, "وهران", ("oran", "wahran")),
    (32, "البيض", ("el bayadh",)),
    (33, "إليزي", ("illizi",)),
    (34, "برج بوعريريج", ("البرج", "برج", "bordj bou arreridj", "bba")),
    (35, "بومرداس", ("boumerdes",)),
    (36, "الطارف", ("el tarf",)),
    (37, "تندوف", ("tindouf",)),
    (38, "تيسمسيلت", ("tissemsilt",)),
    (39, "الوادي", ("وادي سوف", "سوف", "el oued")),
    (40, "خنشلة", ("khenchela",)),
    (41, "سوق أهراس", ("souk ahras",)),
    (42, "تيبازة", ("tipaza", "tipasa")),
    (43, "ميلة", ("mila",)),
    (44, "عين الدفلى", ("ain defla",)),
    (45, "النعامة", ("naama",)),
    (46, "عين تموشنت", ("ain temouchent",)),
    (47, "غرداية", ("ghardaia",)),
    (48, "غليزان", ("relizane", "ighil izane")),
    (49, "تيميمون", ("timimoun",)),
    (50, "برج باجي مختار", ("bordj badji mokhtar",)),
    (51, "أولاد جلال", ("ouled djellal",)),
    (52, "بني عباس", ("beni abbes",)),
    (53, "عين صالح", ("in salah", "ain salah")),
    (54, "عين قزام", ("in guezzam",)),
    (55, "تقرت", ("touggourt",)),
    (56, "جانت", ("djanet",)),
    (57, "المغير", ("el mghair",)),
    (58, "المنيعة", ("el meniaa",)),
]

_BY_CODE = {code: name for code, name, _ in WILAYAS}

# أطول اسم أولاً باش "برج باجي مختار" تتلقى قبل "برج"
_ALIASES: list[tuple[str, int]] = sorted(
    {(normalize(a), code) for code, name, aliases in WILAYAS for a in (name, *aliases)},
    key=lambda x: -len(x[0]),
)
_CODE_RE = re.compile(r"(?:ولايه|ولاية|wilaya|w)\s*(\d{1,2})\b", re.IGNORECASE)


def wilaya_name(code: int) -> str:
    return _BY_CODE.get(code, str(code))


def find_wilaya(text: str) -> tuple[int, str, str] | None:
    """يرجع (الرقم، الاسم، النص اللي تطابق) ولا None."""
    raw = to_latin_digits(text)
    m = _CODE_RE.search(raw)
    if m and 1 <= int(m.group(1)) <= 58:
        code = int(m.group(1))
        return code, _BY_CODE[code], m.group(0)
    norm = f" {normalize(text)} "
    for alias, code in _ALIASES:
        for variant in (alias, "ل" + alias, "ف" + alias, "ب" + alias):
            if f" {variant} " in norm:
                return code, _BY_CODE[code], alias
        # "الوهران" / "لوهران" مع "ال"
        if f" ال{alias} " in norm:
            return code, _BY_CODE[code], alias
    return None
