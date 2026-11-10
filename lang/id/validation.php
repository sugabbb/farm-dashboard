<?php
return [
 'required'=>':attribute wajib diisi.','email'=>':attribute harus berupa email yang valid.','unique'=>':attribute sudah digunakan.',
 'exists'=>':attribute tidak ditemukan.','date'=>':attribute harus berupa tanggal valid.','date_format'=>'Format :attribute harus :format.',
 'numeric'=>':attribute harus berupa angka.','integer'=>':attribute harus berupa bilangan bulat.','array'=>':attribute harus berupa daftar.',
 'string'=>':attribute harus berupa teks.','in'=>'Pilihan :attribute tidak valid.','confirmed'=>'Konfirmasi :attribute tidak cocok.',
 'current_password'=>'Kata sandi saat ini tidak cocok.','accepted'=>':attribute harus disetujui.',
 'min'=>['numeric'=>':attribute minimal :min.','string'=>':attribute minimal :min karakter.','array'=>':attribute minimal :min item.','file'=>':attribute minimal :min KB.'],
 'max'=>['numeric'=>':attribute maksimal :max.','string'=>':attribute maksimal :max karakter.','array'=>':attribute maksimal :max item.','file'=>':attribute maksimal :max KB.'],
 'gt'=>['numeric'=>':attribute harus lebih dari :value.'],'after_or_equal'=>':attribute harus sama atau setelah :date.',
 'before_or_equal'=>':attribute harus sama atau sebelum :date.','file'=>':attribute harus berupa berkas.','mimes'=>':attribute harus berformat :values.',
 'password'=>['letters'=>':attribute harus mengandung huruf.','numbers'=>':attribute harus mengandung angka.'],
 'attributes'=>['email'=>'Email','password'=>'Kata sandi','current_password'=>'Kata sandi saat ini','name'=>'Nama','date'=>'Tanggal','unit'=>'Unit','lines'=>'Rincian','period_start'=>'Awal periode','reason'=>'Alasan','workbook'=>'Workbook','verified'=>'Verifikasi saldo'],
];
