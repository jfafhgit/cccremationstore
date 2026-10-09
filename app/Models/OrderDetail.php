<?php

namespace App\Models;

use App\Enums\HighestDegree;
use App\Enums\MaritalStatus;
use App\Enums\OrderTiming;
use App\Enums\Sex;
use App\Enums\UsState;
use Database\Factories\OrderDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The family's Vital Statistics: what the funeral home needs for the death
 * certificate, collected after payment. Families can save a draft; it
 * counts as complete (and staff are told) once submitted_at is set.
 *
 * @property int $id
 * @property int $order_id
 * @property Sex|null $sex
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $date_of_death
 * @property MaritalStatus|null $marital_status
 * @property HighestDegree|null $highest_degree
 * @property UsState|null $address_state
 * @property UsState|null $birth_state
 * @property bool $born_outside_us
 * @property string|null $ssn Encrypted at rest; never emailed.
 * @property bool|null $veteran_status
 * @property array<string, string>|null $memorial_details Answers keyed as in MEMORIAL_QUESTIONS, plus the chosen 'tone'.
 * @property Carbon|null $submitted_at
 */
class OrderDetail extends Model
{
    /** @use HasFactory<OrderDetailFactory> */
    use HasFactory;

    /** The sections() label the masked Social Security number appears under. */
    public const SSN_LABEL = 'Social Security #';

    /**
     * The extra questions in the Memorial Story section: what a memorial
     * story needs that the death certificate questions don't already ask.
     *
     * @var array<string, string>
     */
    public const MEMORIAL_QUESTIONS = [
        'preferred_name' => 'Name they went by',
        'places_lived' => 'Where they grew up and lived',
        'survived_by' => 'Survived by',
        'preceded_by' => 'Preceded in death by',
        'career' => 'Work, service, and accomplishments',
        'passions' => 'Hobbies, passions, and interests',
        'faith_and_community' => 'Faith and community',
        'remembered_for' => 'What they will be remembered for',
        'memorial_donations' => 'Memorial donations',
    ];

    /**
     * How the memorial story should read, for the AI draft.
     *
     * @var array<string, string>
     */
    public const MEMORIAL_TONES = [
        'traditional' => 'Traditional',
        'warm' => 'Warm and personal',
        'celebration' => 'Celebration of life',
        'faith' => 'Faith-centered',
    ];

    protected $fillable = [
        'order_id',
        'sex',
        'maiden_name',
        'address_line1',
        'address_line2',
        'address_city',
        'address_state',
        'address_zip',
        'phone',
        'date_of_birth',
        'born_outside_us',
        'birth_city',
        'birth_state',
        'birth_place_outside_us',
        'citizenship',
        'ssn',
        'race',
        'hispanic_origin',
        'education_years',
        'highest_degree',
        'occupation',
        'industry',
        'has_pacemaker',
        'date_of_death',
        'place_of_death',
        'marital_status',
        'spouse_first_name',
        'spouse_middle_name',
        'spouse_last_name',
        'spouse_maiden_name',
        'mother_first_name',
        'mother_maiden_name',
        'mother_living',
        'father_first_name',
        'father_last_name',
        'father_living',
        'veteran_status',
        'veteran_branch',
        'next_of_kin_name',
        'next_of_kin_relationship',
        'next_of_kin_phone',
        'next_of_kin_email',
        'obituary_text',
        'memorial_details',
        'service_preferences',
        'additional_notes',
        'answers',
        'submitted_at',
    ];

    protected $hidden = [
        'ssn',
    ];

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'date_of_birth' => 'date',
            'date_of_death' => 'date',
            'address_state' => UsState::class,
            'birth_state' => UsState::class,
            'born_outside_us' => 'boolean',
            'ssn' => 'encrypted',
            'hispanic_origin' => 'boolean',
            'education_years' => 'integer',
            'highest_degree' => HighestDegree::class,
            'has_pacemaker' => 'boolean',
            'marital_status' => MaritalStatus::class,
            'mother_living' => 'boolean',
            'father_living' => 'boolean',
            'veteran_status' => 'boolean',
            'answers' => 'array',
            'memorial_details' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * "•••-••-1234", safe to show without revealing the whole number.
     */
    public function maskedSsn(): ?string
    {
        return $this->ssn ? '•••-••-'.substr($this->ssn, -4) : null;
    }

    /**
     * Everything the family answered, grouped and labelled for staff, with
     * unanswered questions left out. The Social Security number only ever
     * appears masked here; showing it in full is a separate, deliberate step.
     *
     * @return array<string, array<string, string>>
     */
    public function sections(): array
    {
        $order = $this->order;

        $sections = [
            'Next of kin' => [
                'Name' => $this->next_of_kin_name,
                'Relationship' => $this->next_of_kin_relationship,
                'Phone' => $this->next_of_kin_phone,
                'Email' => $this->next_of_kin_email,
            ],
            'Death certificate information' => [
                'Legal name' => $order->deceasedName(),
                'Maiden name' => $this->maiden_name,
                'Sex' => $this->sex?->label(),
                'Date of birth' => $this->date_of_birth?->format('F j, Y'),
                'Place of birth' => $this->placeOfBirth(),
                'Date of passing' => $order->timing === OrderTiming::Immediate ? $this->date_of_death?->format('F j, Y') : null,
                'Place of passing' => $order->timing === OrderTiming::Immediate ? $this->place_of_death : null,
                'Address' => $this->address(),
                'Phone' => $this->phone,
                'Citizen of' => $this->citizenship,
                self::SSN_LABEL => $this->maskedSsn(),
                'Race' => $this->race,
                'Of Hispanic origin' => $this->yesNo($this->hispanic_origin),
                'Education (total years)' => $this->education_years !== null ? (string) $this->education_years : null,
                'Highest degree' => $this->highest_degree?->label(),
                'Last occupation' => $this->occupation,
                'Business / industry' => $this->industry,
                'Pacemaker or defibrillator' => $this->yesNo($this->has_pacemaker),
            ],
            'Marital information' => [
                'Marital status' => $this->marital_status?->label(),
                'Spouse' => $this->marital_status?->hasSpouse() ? $this->joinFilled([$this->spouse_first_name, $this->spouse_middle_name, $this->spouse_last_name]) : null,
                'Spouse maiden name' => $this->marital_status?->hasSpouse() ? $this->spouse_maiden_name : null,
            ],
            'Parents' => [
                'Mother' => $this->parent($this->joinFilled([$this->mother_first_name, $this->mother_maiden_name ? "(maiden name {$this->mother_maiden_name})" : null]), $this->mother_living),
                'Father' => $this->parent($this->joinFilled([$this->father_first_name, $this->father_last_name]), $this->father_living),
            ],
            'Military service' => [
                'Veteran' => $this->yesNo($this->veteran_status),
                'Branch' => $this->veteran_status ? $this->veteran_branch : null,
            ],
            'Memorial Story details' => [
                ...collect(self::MEMORIAL_QUESTIONS)->mapWithKeys(fn (string $label, string $key) => [$label => $this->memorial_details[$key] ?? null])->all(),
                'Tone' => self::MEMORIAL_TONES[$this->memorial_details['tone'] ?? ''] ?? null,
            ],
        ];

        return array_filter(array_map(fn (array $answers): array => array_filter($answers, 'filled'), $sections));
    }

    private function placeOfBirth(): ?string
    {
        if ($this->born_outside_us) {
            return $this->birth_place_outside_us;
        }

        return $this->joinFilled([$this->birth_city, $this->birth_state?->label()], ', ');
    }

    private function address(): ?string
    {
        $cityLine = $this->joinFilled([
            $this->joinFilled([$this->address_city, $this->address_state?->value], ', '),
            $this->address_zip,
        ]);

        return $this->joinFilled([$this->address_line1, $this->address_line2, $cityLine], ', ');
    }

    private function parent(?string $name, ?bool $living): ?string
    {
        if (! $name) {
            return null;
        }

        return match ($living) {
            true => "{$name} — living",
            false => "{$name} — deceased",
            null => $name,
        };
    }

    private function yesNo(?bool $answer): ?string
    {
        return match ($answer) {
            true => 'Yes',
            false => 'No',
            null => null,
        };
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinFilled(array $parts, string $glue = ' '): ?string
    {
        $parts = array_filter($parts, 'filled');

        return $parts === [] ? null : implode($glue, $parts);
    }
}
