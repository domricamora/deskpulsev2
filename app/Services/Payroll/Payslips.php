<?php

namespace App\Services\Payroll;

use App\Http\Controllers\Dashboard\AdjustmentController;
use App\Models\Organization;
use App\Models\Payslip;
use App\Support\Employment;
use App\Support\Format;
use App\Support\Pdf;
use App\Support\Token;
use App\Support\Uploads;
use Illuminate\Support\Facades\Storage;

/**
 * Payslip documents — the lines, the PDF, and the cached copy on disk.
 *
 * Ports payslip_lines(), payslip_pdf(), payslip_generate() and
 * pdf_logo_jpeg(). The layout is reproduced measurement for measurement
 * (decision D8): a payslip is a document people keep and compare against last
 * month's, so re-flowing it is a visible change even when every number is the
 * same.
 *
 * ## Where the files live
 *
 * On the PRIVATE disk, never under the document root — exactly the reason
 * screenshots moved there in Phase 9. A payslip names somebody's salary. The
 * legacy already got this right, writing to `server/storage/` with a deny-all
 * `.htaccess`, and this keeps that property with Laravel's own private disk.
 *
 * One PDF is cached per (user, period) and replaced when regenerated, so old
 * copies do not accumulate for somebody to stumble over.
 *
 * @see docs/migration/payroll.md §4
 */
class Payslips
{
    /**
     * The earning and deduction lines, in print order.
     *
     * Each line is [description, quantity, amount|null, sign]. A null amount
     * is an annotation rather than money — "of which approved overtime" is
     * part of the hours above it, not a second payment.
     *
     * @param  array<string, mixed>  $row  one PayRun row
     * @return list<array{0: string, 1: string, 2: float|null, 3: int}>
     */
    public function lines(array $row): array
    {
        $lines = [];

        if (($row['pay_type'] ?? 'hourly') === 'monthly') {
            $lines[] = ['Monthly salary (prorated for this period)', '', $row['base'], 1];
        } else {
            $lines[] = [
                sprintf('Worked hours @ %s/h', Format::money($row['hourly'], $row['currency'])),
                number_format($row['hours'], 2) . ' h',
                $row['base'],
                1,
            ];
        }

        if ($row['overtime_hours'] > 0.001) {
            // An annotation: this time is already inside the hours above.
            $lines[] = ['— of which approved overtime', number_format($row['overtime_hours'], 2) . ' h', null, 1];
        }

        if ($row['leave_pay'] > 0.001) {
            $lines[] = ['Paid time off', number_format($row['leave_paid_hours'], 2) . ' h', $row['leave_pay'], 1];
        } elseif ($row['leave_days'] > 0) {
            // Salaried: the day off is already covered, so it is recorded
            // without an amount rather than paid twice.
            $lines[] = ['Time off taken (covered by salary)', number_format($row['leave_days'], 2) . ' d', null, 1];
        }

        foreach ($row['adjustments'] as $adjustment) {
            $kind = AdjustmentController::KINDS[$adjustment->kind][0] ?? ucfirst($adjustment->kind);

            $lines[] = [
                $kind . ' — ' . $adjustment->label,
                (string) $adjustment->effective_date,
                (float) $adjustment->amount,
                (int) $adjustment->sign,
            ];
        }

        return $lines;
    }

    /**
     * Render one payslip as PDF bytes.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     */
    public function render(array $row, array $context, Organization $organization): string
    {
        $pdf = new Pdf();
        $margin = $pdf->margin;
        $right = $pdf->width() - $margin;
        $currency = $row['currency'];
        $top = $margin;

        $logo = $this->logoJpeg($organization->logo_path, 34);

        if ($logo) {
            $pdf->image($logo['data'], $margin, $top, $logo['w'], $logo['h']);
            $top += $logo['h'] + 10;
        }

        $pdf->setFont(true, 17)->setColor(15, 23, 42)->text($margin, $top, $organization->name ?: 'DeskPulse');
        $pdf->setFont(true, 17)->setColor(37, 99, 235)->textRight($right, $top, 'PAYSLIP');
        $top += 24;
        $pdf->line($margin, $top, $right, $top, 1.2, [37, 99, 235]);
        $top += 16;

        /* Employee and period */
        $pdf->setFont(false, 9)->setColor(100, 116, 139);
        $pdf->text($margin, $top, 'EMPLOYEE');
        $pdf->textRight($right, $top, 'PAY PERIOD');
        $top += 13;

        $pdf->setFont(true, 11)->setColor(15, 23, 42);
        $pdf->text($margin, $top, $row['name']);
        $pdf->textRight($right, $top, $context['label']);
        $top += 14;

        $pdf->setFont(false, 9)->setColor(71, 85, 105);
        $jobTitle = trim((string) ($row['user']->job_title ?? ''));

        if ($jobTitle !== '') {
            $pdf->text($margin, $top, $jobTitle);
        }

        $pdf->textRight($right, $top, $context['start_date'] . '  to  ' . $context['end_date']);
        $top += 12;

        $reference = trim((string) ($row['user']->external_ref ?? ''));

        if ($reference !== '') {
            $pdf->text($margin, $top, 'Employee ref: ' . $reference);
        }

        $pdf->textRight($right, $top, Employment::label($row['employment_type']));
        $top += 22;

        /* Lines */
        $descriptionColumn = $margin;
        $quantityColumn = $right - 200;
        $amountColumn = $right;

        $pdf->fillRect($margin, $top - 4, $right - $margin, 18, [241, 245, 249]);
        $pdf->setFont(true, 9)->setColor(71, 85, 105);
        $pdf->text($descriptionColumn + 4, $top, 'DESCRIPTION');
        $pdf->text($quantityColumn, $top, 'QTY');
        $pdf->textRight($amountColumn - 4, $top, 'AMOUNT');
        $top += 20;

        foreach ($this->lines($row) as [$description, $quantity, $amount, $sign]) {
            $pdf->ensure(18);
            $top = max($top, $pdf->cursor);

            $pdf->setFont(false, 9.5)->setColor(30, 41, 59);
            $wrapped = $pdf->wrap($description, $quantityColumn - $descriptionColumn - 12);

            foreach ($wrapped as $index => $line) {
                $pdf->text($descriptionColumn + 4, $top + $index * 11, $line);
            }

            if ($quantity !== '') {
                $pdf->setColor(71, 85, 105)->text($quantityColumn, $top, (string) $quantity);
            }

            if ($amount !== null) {
                $negative = $sign < 0;
                $pdf->setColor($negative ? 185 : 30, $negative ? 28 : 41, $negative ? 28 : 59);
                $pdf->textRight(
                    $amountColumn - 4,
                    $top,
                    ($negative ? '-' : '') . Format::money(abs($amount), $currency)
                );
            }

            $top += 11 * max(1, count($wrapped)) + 5;
            $pdf->line($margin, $top - 3, $right, $top - 3, 0.4, [226, 232, 240]);
            $pdf->cursor = $top;
        }

        /* Totals */
        $top += 8;
        $pdf->setFont(false, 10)->setColor(71, 85, 105);
        $pdf->textRight($amountColumn - 90, $top, 'Gross pay');
        $pdf->setFont(true, 10)->setColor(30, 41, 59)
            ->textRight($amountColumn - 4, $top, Format::money($row['gross'], $currency));
        $top += 15;

        $pdf->setFont(false, 10)->setColor(71, 85, 105)->textRight($amountColumn - 90, $top, 'Deductions');
        $pdf->setFont(true, 10)->setColor(185, 28, 28)
            ->textRight($amountColumn - 4, $top, '-' . Format::money($row['deductions'], $currency));
        $top += 8;

        $pdf->line($amountColumn - 220, $top + 6, $right, $top + 6, 0.8, [148, 163, 184]);
        $top += 18;

        $pdf->fillRect($amountColumn - 220, $top - 5, 220, 24, [37, 99, 235]);
        $pdf->setFont(true, 12)->setColor(255, 255, 255);
        $pdf->text($amountColumn - 212, $top + 1, 'NET PAY');
        $pdf->textRight($amountColumn - 8, $top + 1, Format::money($row['net'], $currency));
        $top += 36;

        /* Payment method and footer */
        $account = $row['wise'] ?? null;

        $pdf->setFont(false, 9)->setColor(100, 116, 139);
        $pdf->text($margin, $top, 'Payment method: ' . ($account
            ? 'Wise — ' . ($account->account_summary ?: 'Wise account')
                . ' (' . ($account->target_currency ?: $currency) . ')'
            : 'Not set'));
        $top += 12;

        if ($row['hours'] > 0) {
            $pdf->text($margin, $top, sprintf('Hours logged this period: %.2f h', $row['hours']));
            $top += 12;
        }

        foreach ($row['leave_by_type'] as $type => $days) {
            $pdf->text($margin, $top, sprintf('%s: %.2f day(s)', $type, $days));
            $top += 12;
        }

        $pdf->setColor(148, 163, 184)->setFont(false, 8);
        $pdf->text(
            $margin,
            $pdf->height() - $margin - 10,
            'Generated by DeskPulse on ' . gmdate('j M Y H:i') . ' UTC. This is a computer-generated payslip.'
        );

        return $pdf->output();
    }

    /**
     * Generate (and cache) one member's payslip for a period.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: Payslip}  [path on the private disk, row]
     */
    public function generate(array $row, array $context, Organization $organization, bool $force = false): array
    {
        $disk = Storage::disk('private');

        $existing = Payslip::query()
            ->where('user_id', $row['user_id'])
            ->where('period_start', $context['start_date'])
            ->where('period_end', $context['end_date'])
            ->first();

        if ($existing && ! $force && $existing->pdf_path && $disk->exists($existing->pdf_path)) {
            return [$existing->pdf_path, $existing];
        }

        $path = sprintf(
            'payslips/%d/%s_%s/%d-%s.pdf',
            $organization->id,
            $context['start_date'],
            $context['end_date'],
            $row['user_id'],
            Token::hex(6)
        );

        $disk->put($path, $this->render($row, $context, $organization));

        // Replace the previous file rather than leaving it behind.
        if ($existing && $existing->pdf_path && $existing->pdf_path !== $path) {
            $disk->delete($existing->pdf_path);
        }

        $payslip = Payslip::query()->updateOrCreate(
            [
                'user_id'      => $row['user_id'],
                'period_start' => $context['start_date'],
                'period_end'   => $context['end_date'],
            ],
            [
                'org_id'       => $organization->id,
                'cycle'        => $context['cycle'],
                'hours'        => round($row['hours'], 2),
                'gross'        => round($row['gross'], 2),
                'deductions'   => round($row['deductions'], 2),
                'net'          => round($row['net'], 2),
                'currency'     => $row['currency'],
                'breakdown'    => json_encode($this->lines($row)),
                'pdf_path'     => $path,
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return [$path, $payslip];
    }

    /**
     * An organization logo as JPEG bytes, for embedding.
     *
     * Logos are stored as WebP and PDF cannot embed WebP, so GD re-encodes.
     * Flattened onto white because JPEG has no alpha and payslips print on
     * white anyway.
     *
     * @return array{data: string, w: int, h: int}|null
     */
    public function logoJpeg(?string $logoPath, int $maxHeight = 42): ?array
    {
        if (! $logoPath || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $absolute = Uploads::path($logoPath);

        if (! is_file($absolute)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($absolute));

        if (! $image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, $maxHeight / max(1, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $flat = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopyresampled($flat, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($flat, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);
        imagedestroy($flat);

        return ['data' => $bytes, 'w' => $newWidth, 'h' => $newHeight];
    }
}
