@extends('web::layouts.grids.12')

@section('title', 'Affinity - About')

@section('full')
    <div class="row">
        <div class="col-md-12">

            <div class="card">

                <div class="card-header with-border">
                    <h3 class="card-title">
                        <i class="fas fa-project-diagram"></i>
                        SeAT-Affinity
                    </h3>
                </div>
                <div class="card-body">
                    <p>
                        <strong>SeAT-Affinity</strong> extends SeAT with advanced relationship and trust mapping,
                        helping you understand how characters, corporations, and alliances are connected.
                    </p>

                    <ul>
                        <li>🔗 <strong>Affiliation Mapping</strong> – Visualize and explore connections between characters,
                            corporations, and alliances.</li>
                        <li>👥 <strong>Alt Detection</strong> – Identify and group alternate characters under the same user
                            for easier management.</li>
                        <li>📊 <strong>Network Insights</strong> – Generate detailed reports on organizational structures
                            and shared histories.</li>
                        <li>🛡 <strong>Trust Scores</strong> – Calculate and display affinity scores based on historical
                            affiliations and overlaps.</li>
                        <li>⚙ <strong>SeAT Integration</strong> – Designed to seamlessly extend the existing SeAT interface
                            and permission system.</li>
                    </ul>

                    <p>
                        SeAT-Affinity is built to provide <em>clarity, transparency, and confidence</em> when analyzing
                        complex EVE Online organizations and player networks.
                    </p>
                </div>

            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">

            <div class="card box-solid">

                <div class="card-header with-border">
                    <h3 class="card-title">
                        <i class="fas fa-building"></i>
                        CCP Disclaimer
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-justify">
                        <strong><a href="https://www.eveonline.com" target="_blank">EVE Online</a></strong> and the EVE logo
                        are the
                        registered trademarks of <strong><a href="https://www.ccpgames.com" target="_blank">CCP
                                hf</a></strong>.
                        All rights are reserved worldwide. All other trademarks are the property of their respective owners.
                        EVE Online, the EVE logo, EVE and all associated logos and designs are the intellectual property of
                        CCP hf.
                        All artwork, screenshots, characters, vehicles, storylines, world facts or other recognizable
                        features of
                        the intellectual property relating to these trademarks are likewise the intellectual property of CCP
                        hf.
                        CCP hf. has granted permission to <strong><a href="http://github.com/eveseat/seat/"
                                target="_blank">SeAT</a></strong>
                        to use EVE Online and all associated logos and designs for promotional and information purposes on
                        its project but does not endorse, and is not in any way affiliated with, SeAT. CCP is in no way
                        responsible
                        for the content on or functioning of this software, nor can it be liable for any damage arising from
                        the use
                        of this system.
                    </p>
                </div>

            </div>

        </div>
    </div>

    <div class="row">

        <div class="col-md-6">

            <div class="card card-solid">

                <div class="card-header with-border">
                    <h3 class="card-title">
                        <i class="fas fa-comments"></i>
                        Contact Information
                    </h3>
                </div>
                <div class="card-body">
                    <p>Have a question, feedback, or just want to get ahold of me?<br />Ping me on <a
                            href="https://discord.com/users/1206262455731228746" target="_blank">discord</a>.</p>
                    <p>You can also find me on <a href="https://github.com/Gadgetwhir" target="_blank">github</a> if you
                        want to report a bug.</p>
                </div>

            </div>

        </div>

        <div class="col-md-6">

            <div class="row">

                <div class="col-12">
                    <div class="card card-solid">

                        <div class="card-header with-border">
                            <h3 class="card-title">
                                <i class="fas fa-lightbulb"></i>
                                Got an Idea?
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="media">
                                <img class="mr-3" src="//images.evetech.net/corporations/98791817/logo?size=128"
                                    alt="Nyxforge Dynamics" width="64" height="64">
                                <div class="media-body">
                                    <p class="text-justify">
                                        If you have an idea or want to find get involved you can reach out via discord or
                                        find me in game over at <b>Nyxforge Dynamics</b>.
                                    </p>
                                    <p>I strongly discurage sending any donations in game, I am a one man show here and get
                                        my enjoyment out of people actually using my plugins and tools.</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>

    </div>
@endsection