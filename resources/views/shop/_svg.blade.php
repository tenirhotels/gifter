{{-- Логотип из брендбука (оригинальные векторы, не перерисовывать): public/brand/{name}.svg; цвет — currentColor. --}}
{!! is_file($p = public_path('brand/'.$name.'.svg')) ? file_get_contents($p) : e(config('gifter.certificate.hotel_name')) !!}
