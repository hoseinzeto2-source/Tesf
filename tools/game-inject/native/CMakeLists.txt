cmake_minimum_required(VERSION 3.18)
project(ssm_research_hud)

set(CMAKE_CXX_STANDARD 17)

set(IMGUI_DIR ${CMAKE_CURRENT_SOURCE_DIR}/third_party)
set(NATIVE_DIR ${CMAKE_CURRENT_SOURCE_DIR})

add_library(ssm_research_hud SHARED
    hud_main.cpp
    telemetry.cpp
    memory_safe.cpp
    game_hooks.cpp
    touch_input.cpp
    log_ring.cpp
    github_gist.cpp
    diagnostic.cpp
    heap_scan.cpp
    ${IMGUI_DIR}/imgui.cpp
    ${IMGUI_DIR}/imgui_draw.cpp
    ${IMGUI_DIR}/imgui_tables.cpp
    ${IMGUI_DIR}/imgui_widgets.cpp
    ${IMGUI_DIR}/backends/imgui_impl_opengl3.cpp
    ${IMGUI_DIR}/And64InlineHook.cpp
)

target_include_directories(ssm_research_hud PRIVATE
    ${IMGUI_DIR}
    ${IMGUI_DIR}/backends
)

target_compile_definitions(ssm_research_hud PRIVATE
    IMGUI_IMPL_OPENGL_ES3
)

target_link_libraries(ssm_research_hud
    log
    EGL
    GLESv3
    android
    jnigraphics
)
