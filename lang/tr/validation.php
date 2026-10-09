<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Doğrulama mesajları
    |--------------------------------------------------------------------------
    */

    'accepted' => ':attribute kabul edilmelidir.',
    'after' => ':attribute, :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':attribute, :date tarihi veya sonrası olmalıdır.',
    'alpha_dash' => ':attribute yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
    'array' => ':attribute bir liste olmalıdır.',
    'before' => ':attribute, :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':attribute, :date tarihi veya öncesi olmalıdır.',
    'between' => [
        'array' => ':attribute :min ile :max öğe arasında olmalıdır.',
        'file' => ':attribute :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':attribute :min ile :max arasında olmalıdır.',
        'string' => ':attribute :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':attribute evet veya hayır olmalıdır.',
    'confirmed' => ':attribute tekrarı eşleşmiyor.',
    'date' => ':attribute geçerli bir tarih olmalıdır.',
    'distinct' => ':attribute alanında tekrarlanan değer var.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'extensions' => ':attribute şu uzantılardan biri olmalıdır: :values.',
    'file' => ':attribute bir dosya olmalıdır.',
    'gt' => [
        'numeric' => ':attribute, :value değerinden büyük olmalıdır.',
    ],
    'gte' => [
        'numeric' => ':attribute, :value değerinden büyük veya eşit olmalıdır.',
    ],
    'in' => 'Seçilen :attribute geçersiz.',
    'integer' => ':attribute bir tam sayı olmalıdır.',
    'lt' => [
        'numeric' => ':attribute, :value değerinden küçük olmalıdır.',
    ],
    'lte' => [
        'numeric' => ':attribute, :value değerinden küçük veya eşit olmalıdır.',
    ],
    'max' => [
        'array' => ':attribute en fazla :max öğe içerebilir.',
        'file' => ':attribute en fazla :max kilobayt olabilir.',
        'numeric' => ':attribute en fazla :max olabilir.',
        'string' => ':attribute en fazla :max karakter olabilir.',
    ],
    'min' => [
        'array' => ':attribute en az :min öğe içermelidir.',
        'file' => ':attribute en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute en az :min olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
    ],
    'numeric' => ':attribute bir sayı olmalıdır.',
    'password' => [
        'letters' => ':attribute en az bir harf içermelidir.',
        'mixed' => ':attribute en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute en az bir rakam içermelidir.',
        'symbols' => ':attribute en az bir sembol içermelidir.',
        'uncompromised' => 'Bu :attribute bir veri sızıntısında görülmüş; farklı bir parola seçin.',
    ],
    'required' => ':attribute zorunludur.',
    'required_if' => ':other :value olduğunda :attribute zorunludur.',
    'required_with' => ':values varsa :attribute zorunludur.',
    'string' => ':attribute metin olmalıdır.',
    'unique' => 'Bu :attribute zaten kullanılıyor.',

    'attributes' => [
        'email' => 'e-posta',
        'password' => 'parola',
        'name' => 'ad',
        'code' => 'kod',
        'title' => 'başlık',
        'prompt' => 'görev metni',
        'reason' => 'gerekçe',
        'role' => 'rol',
        'file' => 'dosya',
        'kind' => 'tür',
        'scheduled_at' => 'planlanan zaman',
        'starter_code' => 'başlangıç kodu',
        'reference_solution' => 'referans çözüm',
        'test_cases' => 'test durumları',
        'mcq_options' => 'seçenekler',
        'mcq_answer' => 'doğru yanıt',
        'max_score' => 'azami puan',
        'time_limit_seconds' => 'süre sınırı',
        'planning_seconds' => 'planlama süresi',
        'device_profile_id' => 'cihaz',
        'slots_per_stratum' => 'tabaka başına atama sayısı',
        'seed' => 'tohum',
        'body' => 'içerik',
        'section' => 'bölüm',
        'sort_order' => 'sıra',
        'confirmation' => 'onay',
    ],

];
