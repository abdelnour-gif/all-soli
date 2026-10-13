<?php
namespace QueuePro\Support;

defined('QP_APP') || exit;

/** Customer/provider-facing messages for every business error code (AR + EN). */
final class Messages
{
    private const MAP = [
        'provider_not_found'   => ['المزوّد غير موجود.', 'Provider not found.'],
        'provider_unavailable' => ['هذا المزوّد غير متاح حالياً.', 'This provider is currently unavailable.'],
        'queue_closed'         => ['الطابور مغلق حالياً، حاول لاحقاً.', 'The queue is closed right now. Please try later.'],
        'booking_paused'       => ['الحجز متوقف مؤقتاً لدى هذا المزوّد. يمكنك الحضور مباشرة أو المحاولة لاحقاً.', 'Online booking is temporarily paused for this provider. Please visit in person or try again later.'],
        'queue_full'           => ['الطابور ممتلئ حالياً، حاول بعد قليل.', 'The queue is full right now. Please try again shortly.'],
        'already_in_queue'     => ['لديك تذكرة نشطة بالفعل لدى هذا المزوّد.', 'You already have an active ticket with this provider.'],
        'invalid_phone'        => ['رقم الهاتف غير صالح.', 'Invalid phone number.'],
        'invalid_name'         => ['الاسم غير صالح.', 'Invalid name.'],
        'invalid_service'      => ['الخدمة المختارة غير متاحة.', 'The selected service is not available.'],
        'rate_limited'         => ['محاولات كثيرة، يرجى الانتظار قليلاً.', 'Too many attempts. Please wait a moment.'],
        'ticket_not_found'     => ['التذكرة غير موجودة.', 'Ticket not found.'],
        'invalid_transition'   => ['لا يمكن تنفيذ هذا الإجراء على التذكرة في حالتها الحالية.', 'This action is not allowed in the ticket\'s current state.'],
        'already_delayed'      => ['تم تأجيل هذه التذكرة من قبل، التأجيل مسموح مرة واحدة فقط.', 'This ticket was already delayed once.'],
        'forbidden'            => ['ليست لديك صلاحية لهذا الإجراء.', 'You are not allowed to do this.'],
        'insufficient_credit'  => ['الرصيد غير كافٍ لهذه العملية.', 'Not enough credit for this operation.'],
        'invalid_amount'       => ['الكمية غير صالحة.', 'Invalid amount.'],
        'invalid_input'        => ['بيانات غير صالحة.', 'Invalid input.'],
        'slug_taken'           => ['هذا الرابط مستخدم من قبل مزوّد آخر.', 'This link is already used by another provider.'],
        'otp_required'         => ['أدخل رمز التحقق الذي وصلك برسالة SMS.', 'Enter the verification code we sent you by SMS.'],
        'otp_invalid'          => ['رمز التحقق غير صحيح.', 'Incorrect verification code.'],
        'otp_expired'          => ['انتهت صلاحية الرمز، اطلب رمزاً جديداً.', 'The code has expired. Request a new one.'],
        'otp_cooldown'         => ['انتظر قليلاً قبل طلب رمز جديد.', 'Please wait before requesting a new code.'],
        'otp_unavailable'      => ['خدمة الرسائل غير مفعّلة حالياً.', 'SMS verification is not available right now.'],
        'no_active_ticket'     => ['لا توجد تذكرة نشطة بهذا الرقم لدى هذا المزوّد.', 'No active ticket for this number at this provider.'],
        'rating_not_allowed'   => ['التقييم متاح فقط للخدمات المكتملة خلال الأيام الأخيرة.', 'Ratings are only allowed for recently completed services.'],
        'already_rated'        => ['لقد قيّمت هذه الخدمة من قبل، شكراً لك.', 'You already rated this service. Thank you.'],
        'user_exists'          => ['اسم المستخدم أو البريد مستخدم من قبل.', 'This username or email is already used.'],
        'weak_password'        => ['كلمة المرور يجب أن تكون 10 أحرف على الأقل.', 'The password must be at least 10 characters.'],
        'login_failed'         => ['بيانات الدخول غير صحيحة.', 'Wrong username or password.'],
        'unauthorized'         => ['يجب تسجيل الدخول.', 'Please sign in.'],
        'csrf'                 => ['انتهت صلاحية الصفحة، أعد تحميلها.', 'This page expired. Please reload it.'],
        'not_found'            => ['الصفحة غير موجودة.', 'Not found.'],
        'payload_too_large'    => ['الملف كبير جداً.', 'The file is too large.'],
        'server_error'         => ['حدث خطأ في الخادم، حاول مرة أخرى.', 'Server error, please try again.'],
        'duplicate_request'    =>['تمت معالجة هذا الطلب مسبقاً.', 'This request was already processed.'],
    ];

    public static function get(string $code, string $lang = 'ar'): string
    {
        $pair = self::MAP[$code] ?? ['حدث خطأ غير متوقع.', 'Unexpected error.'];
        return $lang === 'en' ? $pair[1] : $pair[0];
    }

    public static function codes(): array
    {
        return array_keys(self::MAP);
    }
}
