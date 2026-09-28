<?php

namespace App\Enums;

enum HighestDegree: string
{
    case HighSchool = 'high_school';
    case Associate = 'associate';
    case Bachelors = 'bachelors';
    case Masters = 'masters';
    case Doctoral = 'doctoral';

    public function label(): string
    {
        return match ($this) {
            self::HighSchool => 'High school diploma or GED',
            self::Associate => 'Associate',
            self::Bachelors => 'Bachelor’s',
            self::Masters => 'Master’s',
            self::Doctoral => 'Doctorate or professional degree',
        };
    }
}
