<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SmsTemplate;
use Illuminate\Support\Collection;

class SmsTemplateService
{
    public function defaults(): array
    {
        return [
            SmsTemplate::TIME_IN => ['name' => 'Time In', 'body' => 'Hi {{name}}, your attendance was recorded at {{time}}.'],
            SmsTemplate::TIME_OUT => ['name' => 'Time Out', 'body' => 'Hi {{name}}, your time out was recorded at {{time}}.'],
            SmsTemplate::LATE => ['name' => 'Late Arrival', 'body' => 'Hi {{name}}, your late arrival was recorded at {{time}}.'],
            SmsTemplate::ABSENT => ['name' => 'Absent', 'body' => 'Hi {{name}}, you were marked absent for {{date}}.'],
        ];
    }

    public function ensureDefaults(Organization $organization): Collection
    {
        foreach ($this->defaults() as $key => $default) {
            $organization->smsTemplates()->firstOrCreate(
                ['template_key' => $key],
                ['name' => $default['name'], 'body' => $default['body'], 'enabled' => true],
            );
        }

        return $organization->smsTemplates()->orderBy('id')->get();
    }
}
