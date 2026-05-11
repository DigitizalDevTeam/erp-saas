<?php

namespace Modules\HotelManagement\Support;

class HotelNationalityOptions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $countries = [
            'Algeria', 'Angola', 'Argentina', 'Australia', 'Austria', 'Belgium', 'Benin', 'Botswana',
            'Brazil', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cameroon', 'Canada', 'Cape Verde',
            'Central African Republic', 'Chad', 'Chile', 'China', 'Colombia', 'Comoros',
            'Congo - Brazzaville', 'Congo - Kinshasa', 'Costa Rica', 'Croatia', 'Cuba', 'Cyprus',
            'Czech Republic', 'Denmark', 'Djibouti', 'Dominican Republic', 'Egypt',
            'Equatorial Guinea', 'Eritrea', 'Eswatini', 'Ethiopia', 'Finland', 'France', 'Gabon',
            'Gambia', 'Germany', 'Ghana', 'Greece', 'Guinea', 'Guinea-Bissau', 'Hungary', 'India',
            'Indonesia', 'Ireland', 'Israel', 'Italy', 'Ivory Coast', 'Japan', 'Kenya', 'Lesotho',
            'Liberia', 'Libya', 'Luxembourg', 'Madagascar', 'Malawi', 'Malaysia', 'Mali', 'Malta',
            'Mauritania', 'Mauritius', 'Mexico', 'Morocco', 'Mozambique', 'Namibia', 'Netherlands',
            'New Zealand', 'Niger', 'Nigeria', 'Norway', 'Pakistan', 'Paraguay', 'Peru', 'Poland',
            'Portugal', 'Qatar', 'Romania', 'Rwanda', 'Sao Tome and Principe', 'Saudi Arabia',
            'Senegal', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia',
            'Somalia', 'South Africa', 'South Korea', 'South Sudan', 'Spain', 'Sudan', 'Sweden',
            'Switzerland', 'Tanzania', 'Thailand', 'Togo', 'Tunisia', 'Turkey', 'Uganda',
            'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay',
            'Vietnam', 'Zambia', 'Zimbabwe',
        ];

        $countries = array_unique($countries);
        sort($countries);

        return array_combine($countries, $countries) ?: [];
    }
}
