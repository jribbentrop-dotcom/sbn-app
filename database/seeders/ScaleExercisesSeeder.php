<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

class ScaleExercisesSeeder extends Seeder
{
    public function run(): void
    {
        // G Major Scale (first position)
        Exercise::updateOrCreate(['slug' => 'g-major-scale'], [
            'slug' => 'g-major-scale',
            'title' => 'G Major Scale',
            'key_center' => 'G',
            'time_sig' => '4/4',
            'bpm_default' => 60,
            'type' => 'tab_exercise',
            'content_json' => [
                'title' => 'G Major Scale',
                'composer' => 'Ribbentrop',
                'key' => 'G',
                'tempo' => 60,
                'timeSignature' => '4/4',
                'displayBeats' => 4,
                'subdivisionsPerBar' => 8,
                'sections' => [
                    [
                        'id' => 'A',
                        'name' => 'Main',
                        'rhythmSlug' => null,
                        'tonality' => '',
                        'lineBreaks' => [1],
                        'measures' => [
                            [
                                'chords' => [
                                    [
                                        'name' => 'G',
                                        'beats' => 4,
                                        'beatInMeasure' => 0
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'chordVoicings' => [
                    'G@1.0' => [
                        'frets' => '320003',
                        'position' => 1,
                        'fingers' => '210004'
                    ],
                    'G@0.0' => [
                        'frets' => '320003',
                        'position' => 1,
                        'fingers' => '210004'
                    ]
                ],
                'rhythmPattern' => null,
                'melody' => [
                    [
                        'tick' => 0,
                        'pitch' => 'G',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 240,
                        'pitch' => 'A',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 5,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 480,
                        'pitch' => 'B',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 720,
                        'pitch' => 'C',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 960,
                        'pitch' => 'D',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1200,
                        'pitch' => 'E',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1440,
                        'pitch' => 'F#',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 4,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1680,
                        'pitch' => 'G',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 3,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ]
                ],
                'description' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'measures' => [
                    [
                        'chords' => [
                            [
                                'name' => 'G',
                                'beats' => 4,
                                'beatInMeasure' => 0
                            ]
                        ]
                    ]
                ],
                'repeatMarkers' => null,
                'voltaEndings' => null,
                'videoSync' => [
                    'videoId' => '',
                    'videoType' => 'youtube',
                    'mappings' => [],
                    'audioSource' => 'synth'
                ]
            ]
        ]);

        // E Minor Scale (first position)
        Exercise::updateOrCreate(['slug' => 'e-minor-scale'], [
            'slug' => 'e-minor-scale',
            'title' => 'E Minor Scale',
            'key_center' => 'E',
            'time_sig' => '4/4',
            'bpm_default' => 120,
            'type' => 'tab_exercise',
            'content_json' => [
                'title' => 'E Minor Scale',
                'composer' => 'Ribbentrop',
                'key' => 'E',
                'tempo' => 120,
                'timeSignature' => '4/4',
                'displayBeats' => 4,
                'subdivisionsPerBar' => 8,
                'sections' => [
                    [
                        'id' => 'A',
                        'name' => 'Main',
                        'rhythmSlug' => null,
                        'tonality' => '',
                        'lineBreaks' => null,
                        'measures' => [
                            [
                                'chords' => [
                                    [
                                        'name' => 'Em',
                                        'beats' => 4,
                                        'beatInMeasure' => 0
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'chordVoicings' => [
                    'Em@0.0' => [
                        'frets' => '022000',
                        'position' => 1,
                        'fingers' => '023000'
                    ]
                ],
                'rhythmPattern' => null,
                'melody' => [
                    [
                        'tick' => 0,
                        'pitch' => 'E',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 240,
                        'pitch' => 'E',
                        'octave' => 2,
                        'duration' => 'q',
                        'ticks' => 480,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 720,
                        'pitch' => 'E',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 960,
                        'pitch' => 'F#',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 4,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1200,
                        'pitch' => 'G',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1440,
                        'pitch' => 'A',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1680,
                        'pitch' => 'B',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 4,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1920,
                        'pitch' => 'C',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 3,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 2160,
                        'pitch' => 'D',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 3,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ]
                ],
                'description' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'measures' => [
                    [
                        'chords' => [
                            [
                                'name' => 'Em',
                                'beats' => 4,
                                'beatInMeasure' => 0
                            ]
                        ]
                    ]
                ],
                'repeatMarkers' => null,
                'voltaEndings' => null,
                'videoSync' => [
                    'videoId' => '',
                    'videoType' => 'youtube',
                    'mappings' => [],
                    'audioSource' => 'synth'
                ]
            ]
        ]);

        // F Major Scale (first position)
        Exercise::updateOrCreate(['slug' => 'f-major-scale'], [
            'slug' => 'f-major-scale',
            'title' => 'F Major Scale',
            'key_center' => 'F',
            'time_sig' => '4/4',
            'bpm_default' => 60,
            'type' => 'tab_exercise',
            'content_json' => [
                'title' => 'F Major Scale',
                'composer' => 'Ribbentrop',
                'key' => 'F',
                'tempo' => 60,
                'timeSignature' => '4/4',
                'displayBeats' => 4,
                'subdivisionsPerBar' => 8,
                'sections' => [
                    [
                        'id' => 'A',
                        'name' => 'Main',
                        'rhythmSlug' => null,
                        'tonality' => '',
                        'lineBreaks' => [1],
                        'measures' => [
                            [
                                'chords' => [
                                    [
                                        'name' => 'F',
                                        'beats' => 4,
                                        'beatInMeasure' => 0
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'chordVoicings' => [
                    'F@1.0' => [
                        'frets' => '133211',
                        'position' => 1,
                        'fingers' => '134211'
                    ],
                    'F@0.0' => [
                        'frets' => '133211',
                        'position' => 1,
                        'fingers' => '134211'
                    ]
                ],
                'rhythmPattern' => null,
                'melody' => [
                    [
                        'tick' => 0,
                        'pitch' => 'F',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 1,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 240,
                        'pitch' => 'G',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 480,
                        'pitch' => 'A',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 6,
                        'fret' => 5,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 720,
                        'pitch' => 'Bb',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 1,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 960,
                        'pitch' => 'C',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1200,
                        'pitch' => 'D',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1440,
                        'pitch' => 'E',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1680,
                        'pitch' => 'F',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ]
                ],
                'description' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'measures' => [
                    [
                        'chords' => [
                            [
                                'name' => 'F',
                                'beats' => 4,
                                'beatInMeasure' => 0
                            ]
                        ]
                    ]
                ],
                'repeatMarkers' => null,
                'voltaEndings' => null,
                'videoSync' => [
                    'videoId' => '',
                    'videoType' => 'youtube',
                    'mappings' => [],
                    'audioSource' => 'synth'
                ]
            ]
        ]);

        // D Minor Scale (first position)
        Exercise::updateOrCreate(['slug' => 'd-minor-scale'], [
            'slug' => 'd-minor-scale',
            'title' => 'D Minor Scale',
            'key_center' => 'D',
            'time_sig' => '4/4',
            'bpm_default' => 120,
            'type' => 'tab_exercise',
            'content_json' => [
                'title' => 'D Minor Scale',
                'composer' => 'Ribbentrop',
                'key' => 'D',
                'tempo' => 120,
                'timeSignature' => '4/4',
                'displayBeats' => 4,
                'subdivisionsPerBar' => 8,
                'sections' => [
                    [
                        'id' => 'A',
                        'name' => 'Main',
                        'rhythmSlug' => null,
                        'tonality' => '',
                        'lineBreaks' => null,
                        'measures' => [
                            [
                                'chords' => [
                                    [
                                        'name' => 'Dm',
                                        'beats' => 4,
                                        'beatInMeasure' => 0
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'chordVoicings' => [
                    'Dm@0.0' => [
                        'frets' => 'xx0231',
                        'position' => 1,
                        'fingers' => 'xx0231'
                    ]
                ],
                'rhythmPattern' => null,
                'melody' => [
                    [
                        'tick' => 0,
                        'pitch' => 'D',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 240,
                        'pitch' => 'D',
                        'octave' => 2,
                        'duration' => 'q',
                        'ticks' => 480,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 720,
                        'pitch' => 'D',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 960,
                        'pitch' => 'E',
                        'octave' => 2,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 5,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1200,
                        'pitch' => 'F',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1440,
                        'pitch' => 'G',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'begin',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1680,
                        'pitch' => 'A',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 4,
                        'fret' => 3,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => 'end',
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 1920,
                        'pitch' => 'Bb',
                        'octave' => 3,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 3,
                        'fret' => 0,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ],
                    [
                        'tick' => 2160,
                        'pitch' => 'C',
                        'octave' => 4,
                        'duration' => 'e',
                        'ticks' => 240,
                        'tieStart' => false,
                        'tieStop' => false,
                        'voice' => 1,
                        'string' => 3,
                        'fret' => 2,
                        'isChordNote' => false,
                        'isRest' => false,
                        'beam1' => null,
                        'beam2' => null,
                        'tupletActual' => null,
                        'tupletNormal' => null,
                        'tupletType' => null,
                        'tupletBracket' => false
                    ]
                ],
                'description' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'measures' => [
                    [
                        'chords' => [
                            [
                                'name' => 'Dm',
                                'beats' => 4,
                                'beatInMeasure' => 0
                            ]
                        ]
                    ]
                ],
                'repeatMarkers' => null,
                'voltaEndings' => null,
                'videoSync' => [
                    'videoId' => '',
                    'videoType' => 'youtube',
                    'mappings' => [],
                    'audioSource' => 'synth'
                ]
            ]
        ]);
    }
}
