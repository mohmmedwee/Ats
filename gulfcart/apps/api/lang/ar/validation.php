<?php

/*
 * Arabic validation messages for the rules used so far. Missing keys fall back to English.
 * Extend as new rules are introduced.
 */
return [
    'array' => 'يجب أن يكون حقل :attribute مصفوفة.',
    'confirmed' => 'تأكيد حقل :attribute غير متطابق.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'email' => 'يجب أن يكون حقل :attribute بريداً إلكترونياً صحيحاً.',
    'exists' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'in' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'max' => [
        'string' => 'يجب ألا يتجاوز حقل :attribute :max حرفاً.',
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max عنصراً.',
    ],
    'min' => [
        'string' => 'يجب أن يكون حقل :attribute :min أحرف على الأقل.',
    ],
    'present' => 'يجب أن يكون حقل :attribute موجوداً.',
    'regex' => 'صيغة حقل :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'size' => [
        'string' => 'يجب أن يكون حقل :attribute :size أحرف.',
    ],
    'string' => 'يجب أن يكون حقل :attribute نصاً.',
    'unique' => 'قيمة حقل :attribute مستخدمة من قبل.',
    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت قيمة :attribute في تسريب بيانات. يرجى اختيار قيمة مختلفة.',
    ],
    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الجوال',
        'password' => 'كلمة المرور',
        'login' => 'البريد الإلكتروني أو رقم الجوال',
        'code' => 'رمز التحقق',
        'recovery_code' => 'رمز الاسترداد',
        'roles' => 'الأدوار',
        'token' => 'الرمز',
    ],
];
