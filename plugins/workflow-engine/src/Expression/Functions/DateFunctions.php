<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Expression\Functions;

use DateTime;
use DateTimeImmutable;
use DateInterval;
use Exception;

/**
 * Date Functions Provider | 日期函数提供器
 *
 * Provides date and time manipulation functions for expressions.
 * 为表达式提供日期和时间操作函数。
 *
 * Available functions:
 * - date(format, timestamp?) - Format date
 * - now() - Current datetime
 * - today() - Today's date
 * - timestamp(datetime?) - Get Unix timestamp
 * - strtotime(string) - Parse date string
 * - dateAdd(date, interval) - Add interval to date
 * - dateSub(date, interval) - Subtract interval from date
 * - dateDiff(date1, date2) - Difference between dates
 * - formatDate(date, format) - Format date string
 * - parseDate(string, format) - Parse date string with format
 * - isWeekday(date?) - Check if weekday
 * - isWeekend(date?) - Check if weekend
 * - dayOfWeek(date?) - Get day of week (0-6)
 * - dayOfMonth(date?) - Get day of month (1-31)
 * - monthOfYear(date?) - Get month (1-12)
 * - year(date?) - Get year
 * - age(birthdate) - Calculate age in years
 * - businessDays(start, end) - Calculate business days
 *
 * @package Alkaid\Workflow\Engine\Expression\Functions
 */
class DateFunctions extends AbstractFunctionProvider
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerDateFunctions();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'date';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '日期和时间处理函数';
    }

    /**
     * Register date functions | 注册日期函数
     *
     * @return void
     */
    private function registerDateFunctions(): void
    {
        // now() - Current datetime
        $this->registerFunction('now', function () {
            return date('Y-m-d H:i:s');
        });

        // today() - Today's date
        $this->registerFunction('today', function () {
            return date('Y-m-d');
        });

        // date(format, timestamp?) - Format date
        $this->registerFunction('date', function (string $format, ?int $timestamp = null) {
            return date($format, $timestamp ?? time());
        });

        // timestamp(datetime?) - Get Unix timestamp
        $this->registerFunction('timestamp', function (?string $datetime = null) {
            if ($datetime === null) {
                return time();
            }
            return strtotime($datetime) ?: time();
        });

        // strtotime(string) - Parse date string
        $this->registerFunction('strtotime', function (string $datetime) {
            return strtotime($datetime);
        });

        // dateAdd(date, interval) - Add interval to date
        $this->registerFunction('dateAdd', function (string $date, string $interval) {
            try {
                $dt = new DateTime($date);
                $dt->add(new DateInterval($interval));
                return $dt->format('Y-m-d H:i:s');
            } catch (Exception) {
                return $date;
            }
        });

        // dateSub(date, interval) - Subtract interval from date
        $this->registerFunction('dateSub', function (string $date, string $interval) {
            try {
                $dt = new DateTime($date);
                $dt->sub(new DateInterval($interval));
                return $dt->format('Y-m-d H:i:s');
            } catch (Exception) {
                return $date;
            }
        });

        // dateDiff(date1, date2) - Difference between dates in days
        $this->registerFunction('dateDiff', function (string $date1, string $date2) {
            try {
                $dt1 = new DateTime($date1);
                $dt2 = new DateTime($date2);
                $diff = $dt1->diff($dt2);
                return $diff->days * ($diff->invert ? -1 : 1);
            } catch (Exception) {
                return 0;
            }
        });

        // formatDate(date, format) - Format date string
        $this->registerFunction('formatDate', function (string $date, string $format) {
            try {
                $dt = new DateTime($date);
                return $dt->format($format);
            } catch (Exception) {
                return $date;
            }
        });

        // parseDate(string, format) - Parse date string with format
        $this->registerFunction('parseDate', function (string $dateString, string $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $dateString);
            return $dt ? $dt->format('Y-m-d H:i:s') : null;
        });

        // isWeekday(date?) - Check if weekday
        $this->registerFunction('isWeekday', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                $dayOfWeek = (int)$dt->format('w');
                return $dayOfWeek >= 1 && $dayOfWeek <= 5;
            } catch (Exception) {
                return false;
            }
        });

        // isWeekend(date?) - Check if weekend
        $this->registerFunction('isWeekend', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                $dayOfWeek = (int)$dt->format('w');
                return $dayOfWeek === 0 || $dayOfWeek === 6;
            } catch (Exception) {
                return false;
            }
        });

        // dayOfWeek(date?) - Get day of week (0=Sunday, 6=Saturday)
        $this->registerFunction('dayOfWeek', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                return (int)$dt->format('w');
            } catch (Exception) {
                return 0;
            }
        });

        // dayOfMonth(date?) - Get day of month (1-31)
        $this->registerFunction('dayOfMonth', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                return (int)$dt->format('j');
            } catch (Exception) {
                return 1;
            }
        });

        // monthOfYear(date?) - Get month (1-12)
        $this->registerFunction('monthOfYear', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                return (int)$dt->format('n');
            } catch (Exception) {
                return 1;
            }
        });

        // year(date?) - Get year
        $this->registerFunction('year', function (?string $date = null) {
            try {
                $dt = $date ? new DateTime($date) : new DateTime();
                return (int)$dt->format('Y');
            } catch (Exception) {
                return (int)date('Y');
            }
        });

        // age(birthdate) - Calculate age in years
        $this->registerFunction('age', function (string $birthdate) {
            try {
                $birth = new DateTime($birthdate);
                $now = new DateTime();
                return $birth->diff($now)->y;
            } catch (Exception) {
                return 0;
            }
        });

        // businessDays(start, end) - Calculate business days between dates
        $this->registerFunction('businessDays', function (string $start, string $end) {
            try {
                $startDate = new DateTime($start);
                $endDate = new DateTime($end);
                $businessDays = 0;

                while ($startDate < $endDate) {
                    $dayOfWeek = (int)$startDate->format('w');
                    if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
                        $businessDays++;
                    }
                    $startDate->modify('+1 day');
                }

                return $businessDays;
            } catch (Exception) {
                return 0;
            }
        });

        // addDays(date, days) - Add days to date
        $this->registerFunction('addDays', function (string $date, int $days) {
            try {
                $dt = new DateTime($date);
                $dt->modify("{$days} days");
                return $dt->format('Y-m-d');
            } catch (Exception) {
                return $date;
            }
        });

        // addMonths(date, months) - Add months to date
        $this->registerFunction('addMonths', function (string $date, int $months) {
            try {
                $dt = new DateTime($date);
                $dt->modify("{$months} months");
                return $dt->format('Y-m-d');
            } catch (Exception) {
                return $date;
            }
        });

        // addYears(date, years) - Add years to date
        $this->registerFunction('addYears', function (string $date, int $years) {
            try {
                $dt = new DateTime($date);
                $dt->modify("{$years} years");
                return $dt->format('Y-m-d');
            } catch (Exception) {
                return $date;
            }
        });

        // isBefore(date1, date2) - Check if date1 is before date2
        $this->registerFunction('isBefore', function (string $date1, string $date2) {
            return strtotime($date1) < strtotime($date2);
        });

        // isAfter(date1, date2) - Check if date1 is after date2
        $this->registerFunction('isAfter', function (string $date1, string $date2) {
            return strtotime($date1) > strtotime($date2);
        });

        // isBetween(date, start, end) - Check if date is between start and end
        $this->registerFunction('isBetween', function (string $date, string $start, string $end) {
            $ts = strtotime($date);
            return $ts >= strtotime($start) && $ts <= strtotime($end);
        });
    }
}
