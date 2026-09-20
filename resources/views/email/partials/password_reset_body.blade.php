<p style="margin:0 0 12px">Hi {{ $name }},</p>
<p style="margin:0 0 12px">Someone asked to reset the password for the DeskPulse account
   registered to this address. Use the button below to choose a new one.</p>
<p style="margin:0 0 12px"><strong>This link is valid for {{ (int) $ttl }} minutes</strong>
   and can only be used once.</p>
<p style="margin:0;color:#64748b;font-size:13px">
  If you didn't ask for this, you can ignore this email — your password will not change
  until the link above is used{{ $ip ? ', and nothing has been changed on your account' : '' }}.
  @if ($ip)<br>Requested from IP {{ $ip }}.@endif
</p>
