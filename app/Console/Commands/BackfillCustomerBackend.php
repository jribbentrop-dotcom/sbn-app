<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Course;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCustomerBackend extends Command
{
    protected $signature = 'sbn:backfill-customer-backend
        {--instructor= : Email of the instructor user to flag and grant all courses}
        {--commit : Apply changes (default is dry-run)}';

    protected $description = 'Phase A backfill: instructor flag + all-course grant, profiles for existing users, community channel seed + universal join.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $instructorEmail = $this->option('instructor');

        if (!$commit) {
            $this->warn('DRY RUN — pass --commit to apply.');
        }

        DB::transaction(function () use ($commit, $instructorEmail) {
            $instructor = null;
            if ($instructorEmail) {
                $instructor = User::where('email', $instructorEmail)->first();
                if (!$instructor) {
                    $this->error("Instructor not found: {$instructorEmail}");
                    return;
                }
                $this->line("Instructor: {$instructor->email} (id={$instructor->id})");

                if ($commit) {
                    $instructor->is_instructor = true;
                    $instructor->save();
                }

                $courses = Course::query()->get(['id', 'slug']);
                $this->line("Granting {$courses->count()} courses to instructor.");
                foreach ($courses as $course) {
                    if ($commit) {
                        $instructor->courses()->syncWithoutDetaching([
                            $course->id => [
                                'source'     => 'manual_grant',
                                'granted_at' => now(),
                            ],
                        ]);
                    }
                }
            }

            $userCount = User::count();
            $this->line("Ensuring profiles for {$userCount} users.");
            if ($commit) {
                User::query()->lazyById(200)->each(function (User $u) {
                    UserProfile::firstOrCreate(
                        ['user_id' => $u->id],
                        ['display_name' => $u->name]
                    );
                });
            }

            $channel = Conversation::where('type', Conversation::TYPE_CHANNEL)->first();
            if (!$channel) {
                $this->line('Creating community channel "The Practice Room".');
                if ($commit) {
                    $channel = Conversation::create([
                        'type'  => Conversation::TYPE_CHANNEL,
                        'title' => 'The Practice Room',
                    ]);
                }
            } else {
                $this->line("Community channel exists (id={$channel->id}).");
            }

            if ($commit && $channel) {
                $existing = $channel->participants()->pluck('users.id')->all();
                $toAdd = User::query()->whereNotIn('id', $existing)->pluck('id')->all();
                $this->line('Adding ' . count($toAdd) . ' users to community channel.');
                $now = now();
                foreach (array_chunk($toAdd, 500) as $chunk) {
                    $rows = array_map(fn ($id) => [
                        'conversation_id' => $channel->id,
                        'user_id'         => $id,
                        'joined_at'       => $now,
                    ], $chunk);
                    DB::table('conversation_participants')->insert($rows);
                }
            }
        });

        $this->info($commit ? 'Backfill applied.' : 'Dry run complete.');
        return self::SUCCESS;
    }
}
