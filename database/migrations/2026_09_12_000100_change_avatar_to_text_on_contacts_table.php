<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instagram (and Messenger) profile pictures arrive from Meta's CDN as long
     * signed URLs — routinely 600-900+ characters once the `_nc_oc` / `oh`
     * signature parameters are included. With contacts.avatar capped at
     * varchar(512), storing one threw "Data too long for column 'avatar'" while
     * the Instagram webhook was resolving the sender, which aborted the event
     * before the inbound message was written to the conversation.
     *
     * TEXT removes the length ceiling. The column carries no index, so there is
     * no key-length concern, and it stays nullable (contacts without a picture).
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('avatar')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('avatar', 512)->nullable()->change();
        });
    }
};
