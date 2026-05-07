<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AltNumeralsSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            1 => [
                ['label' => 'with #Vdim leading-tone',
                 'numerals' => 'IIm7,V7,#Vdim7,Imaj7',
                 'notes' => 'Leading-tone diminished resolving up to Imaj7'],
            ],
            2 => [
                ['label' => 'with #Vdim leading-tone',
                 'numerals' => 'IIm7b5,V7,#Vdim7,Im',
                 'notes' => 'Leading-tone diminished resolving up to Im'],
            ],
            5 => [
                ['label' => 'with #Idim sub for VI7',
                 'numerals' => 'I,#Idim7,IIm7,V7',
                 'notes' => 'Diminished sub for the secondary dominant — same function as VI7'],
                ['label' => 'with bIIIdim passing',
                 'numerals' => 'I,VI7,IIm7,bIIIdim7,V7',
                 'notes' => 'Five-slot variant; diminished between ii and V'],
            ],
            9 => [
                ['label' => 'with bIIIdim sub for VI7',
                 'numerals' => 'IIIm7,bIIIdim7,IIm7,V7',
                 'notes' => 'Diminished passing chord'],
            ],
            21 => [
                ['label' => 'with #Idim passing',
                 'numerals' => 'I,#Idim7,IIm,V7',
                 'notes' => 'Diminished passing replacing the VIm'],
            ],
            38 => [
                ['label' => 'with #Idim passing',
                 'numerals' => 'I,#Idim7,IIm,V7',
                 'notes' => 'Diminished passing replacing the VIm'],
            ],
        ];

        foreach ($data as $id => $variants) {
            DB::table('sbn_chord_progressions')
                ->where('id', $id)
                ->update(['alt_numerals' => json_encode($variants)]);
        }
    }
};
