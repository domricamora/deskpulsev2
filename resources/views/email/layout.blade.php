{{--
    Shared HTML shell for outbound email. Ports templates/email/layout.php.

    Expects: $org (array or Organization), $heading, $bodyHtml;
    optional $ctaUrl, $ctaLabel.

    Every style is inline because email clients strip <style> blocks — this is
    not a Tailwind page and must not become one in Phase 18.
--}}
@php
    $orgName = data_get($org, 'name') ?: 'DeskPulse';
    $logoPath = data_get($org, 'logo_path');
    $logo = $logoPath ? url('/uploads/' . $logoPath) : null;
@endphp
<div style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0">
    <tr>
      <td style="padding:20px 26px;border-bottom:3px solid #2563eb">
        @if ($logo)
          <img src="{{ $logo }}" alt="{{ $orgName }}" height="34"
               style="display:block;max-height:34px;border:0">
        @else
          <div style="font-size:19px;font-weight:800;letter-spacing:-.02em">{{ $orgName }}</div>
        @endif
      </td>
    </tr>
    <tr>
      <td style="padding:26px">
        <h1 style="margin:0 0 14px;font-size:19px;line-height:1.35;font-weight:700;color:#0f172a">
          {{ $heading ?? '' }}</h1>
        <div style="font-size:14.5px;line-height:1.65;color:#334155">
          {!! $bodyHtml ?? '' !!}
        </div>
        @if (! empty($ctaUrl))
          <p style="margin:24px 0 0">
            <a href="{{ $ctaUrl }}"
               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;
                      padding:11px 20px;border-radius:6px;font-weight:600;font-size:14px">
              {{ $ctaLabel ?? 'Open DeskPulse' }}</a>
          </p>
        @endif
      </td>
    </tr>
    <tr>
      <td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;
                 font-size:12px;line-height:1.5;color:#64748b">
        Sent by {{ $orgName }} via DeskPulse.
        @if (! empty($ctaUrl))<br>If the button doesn't work, paste this into your browser:<br>
          <span style="word-break:break-all">{{ $ctaUrl }}</span>@endif
      </td>
    </tr>
  </table>
</div>
