<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_company_name_cleanup.go */
final class CompanyTitles
{
    public static function remove(string $in): string
    {
        return Go::replace($in, [
            ' CO.', '',
            ' D.O.O.', '',
            ' INC.', '',
            ' GMBH', '',
            ' LLC', '',
            ' L.L.C.', '',
            ' LLP', '',
            ' LTD.', '',
            ' LTD ', ' ',
            ', LTD', '',
            ' LTDA.', '',
            ' SA DE CV', '',
        ]);
    }
}
